<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\PlatformIntegration;
use App\Entity\User;
use App\Repository\AuthSessionRepository;
use App\Repository\PlatformIntegrationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Provisioning SCIM 2.0 (RFC 7643/7644), sous-ensemble « Users » pour Keycloak, Authentik ou Entra ID.
 *
 * Garde-fous : clé de service portant la portée `scim:write`, limitée à son organisation ; les comptes créés
 * reçoivent uniquement le rôle lecteur et SCIM ne modifie jamais les rôles ni l'adresse email ; la désactivation
 * révoque les sessions et ne supprime rien ; le dernier administrateur actif ne peut pas être désactivé ;
 * un compte verrouillé par RiskPilot n'est pas réactivé par le fournisseur d'identité.
 * L'association SSO (identifiant fournisseur) reste une action d'administration distincte.
 */
#[Route('/api/scim/v2')]
final readonly class ScimController
{
    private const USER_SCHEMA = 'urn:ietf:params:scim:schemas:core:2.0:User';
    private const ERROR_SCHEMA = 'urn:ietf:params:scim:api:messages:2.0:Error';
    private const LIST_SCHEMA = 'urn:ietf:params:scim:api:messages:2.0:ListResponse';
    private const PATCH_SCHEMA = 'urn:ietf:params:scim:api:messages:2.0:PatchOp';
    private const MAX_COUNT = 100;
    private const GROUP_SCHEMA = 'urn:ietf:params:scim:schemas:core:2.0:Group';
    /** Groupes SCIM = rôles attribuables (le rôle lecteur est implicite, l'administration reste gérée dans RiskPilot). */
    private const GROUP_ROLES = [User::ROLE_RISK_MANAGER, User::ROLE_ACTION_OWNER, User::ROLE_AUDITOR];

    public function __construct(
        private PlatformIntegrationRepository $integrations,
        private UserRepository $users,
        private AuthSessionRepository $sessions,
        private UserPasswordHasherInterface $passwordHasher,
        private EntityManagerInterface $entityManager,
        private RateLimiterFactoryInterface $serviceApiLimiter,
    ) {
    }

    #[Route('/ServiceProviderConfig', methods: ['GET'])]
    public function serviceProviderConfig(Request $request): JsonResponse
    {
        $access = $this->access($request);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        return $this->json(['schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig'], 'patch' => ['supported' => true], 'bulk' => ['supported' => false, 'maxOperations' => 0, 'maxPayloadSize' => 0], 'filter' => ['supported' => true, 'maxResults' => self::MAX_COUNT], 'changePassword' => ['supported' => false], 'sort' => ['supported' => false], 'etag' => ['supported' => false], 'authenticationSchemes' => [['type' => 'oauthbearertoken', 'name' => 'Clé de service RiskPilot', 'description' => 'Jeton Bearer ou en-tête X-RiskPilot-Key avec la portée scim:write.']]]);
    }

    #[Route('/Users', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $access = $this->access($request);
        if ($access instanceof JsonResponse) {
            return $access;
        }
        $organization = $access->getOrganization();
        $filter = trim($request->query->getString('filter'));
        $start = max(1, $request->query->getInt('startIndex', 1));
        $count = min(self::MAX_COUNT, max(0, $request->query->getInt('count', self::MAX_COUNT)));
        if ('' !== $filter) {
            if (1 !== preg_match('/^userName\s+eq\s+"([^"\\\\]{1,180})"$/i', $filter, $match)) {
                return $this->error(400, 'Seul le filtre « userName eq "…" » est pris en charge.', 'invalidFilter');
            }
            $found = $this->users->findOneBy(['email' => mb_strtolower($match[1]), 'organization' => $organization]);
            $all = null === $found ? [] : [$found];
        } else {
            $all = $this->users->findBy(['organization' => $organization], ['id' => 'ASC']);
        }
        $page = array_slice($all, $start - 1, $count);

        return $this->json(['schemas' => [self::LIST_SCHEMA], 'totalResults' => count($all), 'startIndex' => $start, 'itemsPerPage' => count($page), 'Resources' => array_map($this->resource(...), $page)]);
    }

    #[Route('/Users/{id<\d+>}', methods: ['GET'])]
    public function get(int $id, Request $request): JsonResponse
    {
        $access = $this->access($request);
        if ($access instanceof JsonResponse) {
            return $access;
        }
        $user = $this->find($id, $access);

        return null === $user ? $this->error(404, 'Utilisateur introuvable.') : $this->json($this->resource($user));
    }

    #[Route('/Users', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $access = $this->access($request);
        if ($access instanceof JsonResponse) {
            return $access;
        }
        $data = $this->body($request);
        if (null === $data) {
            return $this->error(400, 'Corps JSON invalide.', 'invalidSyntax');
        }
        $email = is_string($data['userName'] ?? null) ? mb_strtolower(trim($data['userName'])) : '';
        $given = $this->name($data['name']['givenName'] ?? null);
        $family = $this->name($data['name']['familyName'] ?? null);
        if (false === filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 180 || null === $given || null === $family) {
            return $this->error(400, 'userName (adresse email valide), name.givenName et name.familyName sont obligatoires.', 'invalidValue');
        }
        if (null !== $this->users->findOneBy(['email' => $email])) {
            return $this->error(409, 'Cet utilisateur existe déjà.', 'uniqueness');
        }
        $active = $this->active($data['active'] ?? true);
        if (null === $active) {
            return $this->error(400, 'Le champ active doit être un booléen.', 'invalidValue');
        }
        $externalId = $this->externalId($data['externalId'] ?? null);
        if (false === $externalId) {
            return $this->error(400, 'externalId invalide (255 caractères maximum, sans caractère de contrôle).', 'invalidValue');
        }
        if (null !== $externalId && $this->externalIdTaken($externalId, $access, null)) {
            return $this->error(409, 'Cet externalId est déjà rattaché à un autre utilisateur.', 'uniqueness');
        }
        $user = new User($email, $given, $family, $access->getOrganization(), [User::ROLE_VIEWER]);
        $user->setScimExternalId($externalId);
        // Le mot de passe est inutilisable : la connexion se fait par SSO ou après une réinitialisation par l'administrateur.
        $user->setPassword($this->passwordHasher->hashPassword($user, bin2hex(random_bytes(32))));
        $user->setStatus($active ? User::STATUS_ACTIVE : User::STATUS_INACTIVE);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $this->json($this->resource($user), 201);
    }

    #[Route('/Users/{id<\d+>}', methods: ['PUT'])]
    public function replace(int $id, Request $request): JsonResponse
    {
        $access = $this->access($request);
        if ($access instanceof JsonResponse) {
            return $access;
        }
        $user = $this->find($id, $access);
        $data = $this->body($request);
        if (null === $user) {
            return $this->error(404, 'Utilisateur introuvable.');
        }
        if (null === $data) {
            return $this->error(400, 'Corps JSON invalide.', 'invalidSyntax');
        }
        if (is_string($data['userName'] ?? null) && mb_strtolower(trim($data['userName'])) !== $user->getEmail()) {
            return $this->error(400, 'L’adresse email ne peut pas être modifiée par SCIM.', 'mutability');
        }

        $changes = ['active' => $data['active'] ?? true, 'givenName' => $data['name']['givenName'] ?? $user->getFirstName(), 'familyName' => $data['name']['familyName'] ?? $user->getLastName()];
        if (array_key_exists('externalId', $data)) {
            $changes['externalId'] = $data['externalId'];
        }

        return $this->apply($user, $access, $changes);
    }

    #[Route('/Users/{id<\d+>}', methods: ['PATCH'])]
    public function patch(int $id, Request $request): JsonResponse
    {
        $access = $this->access($request);
        if ($access instanceof JsonResponse) {
            return $access;
        }
        $user = $this->find($id, $access);
        $data = $this->body($request);
        if (null === $user) {
            return $this->error(404, 'Utilisateur introuvable.');
        }
        if (null === $data || !in_array(self::PATCH_SCHEMA, (array) ($data['schemas'] ?? []), true) || !is_array($data['Operations'] ?? null)) {
            return $this->error(400, 'Requête PATCH SCIM invalide.', 'invalidSyntax');
        }
        $changes = ['active' => User::STATUS_ACTIVE === $user->getStatus(), 'givenName' => $user->getFirstName(), 'familyName' => $user->getLastName()];
        foreach ($data['Operations'] as $operation) {
            if (!is_array($operation) || !in_array(strtolower((string) ($operation['op'] ?? '')), ['replace', 'add'], true)) {
                return $this->error(400, 'Seules les opérations replace et add sont prises en charge.', 'invalidValue');
            }
            $path = $operation['path'] ?? null;
            $values = null === $path ? $operation['value'] ?? null : [$path => $operation['value'] ?? null];
            if (!is_array($values)) {
                return $this->error(400, 'Valeur d’opération invalide.', 'invalidValue');
            }
            foreach ($values as $key => $value) {
                $mapped = match ($key) {
                    'active' => 'active',
                    'name.givenName' => 'givenName',
                    'name.familyName' => 'familyName',
                    'externalId' => 'externalId',
                    default => null,
                };
                if ('name' === $key && is_array($value)) {
                    $changes['givenName'] = $value['givenName'] ?? $changes['givenName'];
                    $changes['familyName'] = $value['familyName'] ?? $changes['familyName'];
                    continue;
                }
                if (null === $mapped) {
                    return $this->error(400, sprintf('L’attribut « %s » ne peut pas être modifié par SCIM.', (string) $key), 'mutability');
                }
                $changes[$mapped] = $value;
            }
        }

        return $this->apply($user, $access, $changes);
    }

    /** Les comptes ne sont jamais supprimés : ils sont désactivés pour conserver responsabilités et historique. */
    #[Route('/Users/{id<\d+>}', methods: ['DELETE'])]
    public function delete(int $id, Request $request): JsonResponse
    {
        $access = $this->access($request);
        if ($access instanceof JsonResponse) {
            return $access;
        }
        $user = $this->find($id, $access);
        if (null === $user) {
            return $this->error(404, 'Utilisateur introuvable.');
        }
        if (User::STATUS_INACTIVE !== $user->getStatus() && $this->isLastActiveAdmin($user)) {
            return $this->error(403, 'Le dernier administrateur actif ne peut pas être désactivé.', 'mutability');
        }
        $user->setStatus(User::STATUS_INACTIVE);
        $this->sessions->revokeAll($user);
        $this->entityManager->flush();

        return new JsonResponse(null, 204, ['Cache-Control' => 'no-store']);
    }

    #[Route('/Groups', methods: ['GET'])]
    public function listGroups(Request $request): JsonResponse
    {
        $access = $this->access($request, 'scim:groups');
        if ($access instanceof JsonResponse) {
            return $access;
        }

        return $this->json(['schemas' => [self::LIST_SCHEMA], 'totalResults' => count(self::GROUP_ROLES), 'startIndex' => 1, 'itemsPerPage' => count(self::GROUP_ROLES), 'Resources' => array_map(fn (string $role): array => $this->group($role, $access), self::GROUP_ROLES)]);
    }

    #[Route('/Groups/{role<ROLE_[A-Z_]+>}', methods: ['GET'])]
    public function getGroup(string $role, Request $request): JsonResponse
    {
        $access = $this->access($request, 'scim:groups');
        if ($access instanceof JsonResponse) {
            return $access;
        }

        return in_array($role, self::GROUP_ROLES, true) ? $this->json($this->group($role, $access)) : $this->error(404, 'Groupe introuvable.');
    }

    /** Les groupes sont fixes : ils correspondent aux rôles que le fournisseur peut attribuer. */
    #[Route('/Groups', methods: ['POST'])]
    public function createGroup(Request $request): JsonResponse
    {
        $access = $this->access($request, 'scim:groups');

        return $access instanceof JsonResponse ? $access : $this->error(403, 'Les groupes correspondent à des rôles fixes et ne peuvent pas être créés.', 'mutability');
    }

    #[Route('/Groups/{role<ROLE_[A-Z_]+>}', methods: ['DELETE'])]
    public function deleteGroup(string $role, Request $request): JsonResponse
    {
        $access = $this->access($request, 'scim:groups');

        return $access instanceof JsonResponse ? $access : $this->error(403, 'Les groupes correspondent à des rôles fixes et ne peuvent pas être supprimés.', 'mutability');
    }

    #[Route('/Groups/{role<ROLE_[A-Z_]+>}', methods: ['PUT'])]
    public function replaceGroup(string $role, Request $request): JsonResponse
    {
        $access = $this->access($request, 'scim:groups');
        if ($access instanceof JsonResponse) {
            return $access;
        }
        $data = $this->body($request);
        if (!in_array($role, self::GROUP_ROLES, true)) {
            return $this->error(404, 'Groupe introuvable.');
        }
        $members = null === $data ? null : $this->memberIds($data['members'] ?? []);
        $users = null === $members ? null : $this->usersByIds($members, $access);
        if (null === $users) {
            return $this->error(400, 'Les membres doivent désigner des utilisateurs de l’organisation.', 'invalidValue');
        }
        foreach ($this->users->findBy(['organization' => $access->getOrganization()]) as $user) {
            $user->setRoles($this->withRole($user->getRoles(), $role, isset($users[$user->getId()])));
        }
        $this->entityManager->flush();

        return $this->json($this->group($role, $access));
    }

    #[Route('/Groups/{role<ROLE_[A-Z_]+>}', methods: ['PATCH'])]
    public function patchGroup(string $role, Request $request): JsonResponse
    {
        $access = $this->access($request, 'scim:groups');
        if ($access instanceof JsonResponse) {
            return $access;
        }
        $data = $this->body($request);
        if (!in_array($role, self::GROUP_ROLES, true)) {
            return $this->error(404, 'Groupe introuvable.');
        }
        if (null === $data || !in_array(self::PATCH_SCHEMA, (array) ($data['schemas'] ?? []), true) || !is_array($data['Operations'] ?? null)) {
            return $this->error(400, 'Requête PATCH SCIM invalide.', 'invalidSyntax');
        }
        $current = [];
        foreach ($this->users->findBy(['organization' => $access->getOrganization()]) as $user) {
            if (in_array($role, $user->getRoles(), true)) {
                $current[$user->getId()] = true;
            }
        }
        foreach ($data['Operations'] as $operation) {
            $op = is_array($operation) ? strtolower((string) ($operation['op'] ?? '')) : '';
            $path = is_array($operation) ? (string) ($operation['path'] ?? 'members') : '';
            if (!in_array($op, ['add', 'replace', 'remove'], true)) {
                return $this->error(400, 'Opération non prise en charge.', 'invalidValue');
            }
            if (1 === preg_match('/^members\[value eq "(\d+)"\]$/i', $path, $match) && 'remove' === $op) {
                $ids = [(int) $match[1]];
            } elseif ('members' === $path) {
                $ids = $this->memberIds($operation['value'] ?? []);
            } else {
                return $this->error(400, 'Seul l’attribut members peut être modifié.', 'mutability');
            }
            $resolved = null === $ids ? null : $this->usersByIds($ids, $access);
            if (null === $resolved) {
                return $this->error(400, 'Les membres doivent désigner des utilisateurs de l’organisation.', 'invalidValue');
            }
            if ('replace' === $op) {
                $current = [];
            }
            foreach (array_keys($resolved) as $id) {
                if ('remove' === $op) {
                    unset($current[$id]);
                } else {
                    $current[$id] = true;
                }
            }
        }
        foreach ($this->users->findBy(['organization' => $access->getOrganization()]) as $user) {
            $user->setRoles($this->withRole($user->getRoles(), $role, isset($current[$user->getId()])));
        }
        $this->entityManager->flush();

        return $this->json($this->group($role, $access));
    }

    /** @return array<string, mixed> */
    private function group(string $role, PlatformIntegration $access): array
    {
        $members = [];
        foreach ($this->users->findBy(['organization' => $access->getOrganization()], ['id' => 'ASC']) as $user) {
            if (in_array($role, $user->getRoles(), true)) {
                $members[] = ['value' => (string) $user->getId(), 'display' => $user->getEmail()];
            }
        }

        return ['schemas' => [self::GROUP_SCHEMA], 'id' => $role, 'displayName' => $role, 'members' => $members, 'meta' => ['resourceType' => 'Group']];
    }

    /**
     * @return list<int>|null
     */
    private function memberIds(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 500) {
            return null;
        }
        $ids = [];
        foreach ($value as $member) {
            $id = is_array($member) ? ($member['value'] ?? null) : null;
            if (!is_string($id) || 1 !== preg_match('/^\d{1,10}$/D', $id)) {
                return null;
            }
            $ids[] = (int) $id;
        }

        return $ids;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, User>|null
     */
    private function usersByIds(array $ids, PlatformIntegration $access): ?array
    {
        $found = [];
        foreach ($ids as $id) {
            $user = $this->find($id, $access);
            if (null === $user) {
                return null;
            }
            $found[$id] = $user;
        }

        return $found;
    }

    /**
     * @param list<string> $roles
     *
     * @return list<string>
     */
    private function withRole(array $roles, string $role, bool $member): array
    {
        $roles = array_values(array_filter($roles, static fn (string $item): bool => $item !== $role));

        return $member ? [...$roles, $role] : $roles;
    }

    /** @param array{active: mixed, givenName: mixed, familyName: mixed, externalId?: mixed} $changes */
    private function apply(User $user, PlatformIntegration $access, array $changes): JsonResponse
    {
        $externalId = array_key_exists('externalId', $changes) ? $this->externalId($changes['externalId']) : $user->getScimExternalId();
        if (false === $externalId) {
            return $this->error(400, 'externalId invalide (255 caractères maximum, sans caractère de contrôle).', 'invalidValue');
        }
        if (null !== $externalId && $externalId !== $user->getScimExternalId() && $this->externalIdTaken($externalId, $access, $user)) {
            return $this->error(409, 'Cet externalId est déjà rattaché à un autre utilisateur.', 'uniqueness');
        }
        $given = $this->name($changes['givenName']);
        $family = $this->name($changes['familyName']);
        $active = $this->active($changes['active']);
        if (null === $given || null === $family || null === $active) {
            return $this->error(400, 'Valeurs invalides pour name ou active.', 'invalidValue');
        }
        if (!$active && User::STATUS_INACTIVE !== $user->getStatus() && $this->isLastActiveAdmin($user)) {
            return $this->error(403, 'Le dernier administrateur actif ne peut pas être désactivé.', 'mutability');
        }
        $user->setFirstName($given)->setLastName($family)->setScimExternalId($externalId);
        if (!$active && User::STATUS_INACTIVE !== $user->getStatus()) {
            $user->setStatus(User::STATUS_INACTIVE);
            $this->sessions->revokeAll($user);
        } elseif ($active && User::STATUS_INACTIVE === $user->getStatus()) {
            // Un compte verrouillé par RiskPilot reste verrouillé : seul un administrateur peut le rétablir.
            $user->setStatus(User::STATUS_ACTIVE);
        }
        $this->entityManager->flush();

        return $this->json($this->resource($user));
    }

    private function isLastActiveAdmin(User $user): bool
    {
        if ([] === array_intersect([User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN], $user->getRoles())) {
            return false;
        }
        foreach ($this->users->findBy(['organization' => $user->getOrganization(), 'status' => User::STATUS_ACTIVE]) as $other) {
            if ($other !== $user && [] !== array_intersect([User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN], $other->getRoles())) {
                return false;
            }
        }

        return true;
    }

    private function find(int $id, PlatformIntegration $access): ?User
    {
        return $this->users->findOneBy(['id' => $id, 'organization' => $access->getOrganization()]);
    }

    /** @return array<string, mixed>|null */
    private function body(Request $request): ?array
    {
        try {
            return $request->toArray();
        } catch (\Throwable) {
            return null;
        }
    }

    private function name(mixed $value): ?string
    {
        return is_string($value) && '' !== trim($value) && mb_strlen(trim($value)) <= 100 ? trim($value) : null;
    }

    /** Accepte les booléens et les chaînes « true »/« false » envoyées par certains fournisseurs (Entra). */
    private function active(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value) && in_array(strtolower($value), ['true', 'false'], true)) {
            return 'true' === strtolower($value);
        }

        return null;
    }

    /** @return string|false|null null : absent ; false : invalide */
    private function externalId(mixed $value): string|false|null
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if (!is_string($value) || strlen($value) > 255 || preg_match('/[\x00-\x20\x7f]/', $value)) {
            return false;
        }

        return $value;
    }

    private function externalIdTaken(string $externalId, PlatformIntegration $access, ?User $except): bool
    {
        $other = $this->users->findOneBy(['organization' => $access->getOrganization(), 'scimExternalId' => $externalId]);

        return null !== $other && $other !== $except;
    }

    /** @return array<string, mixed> */
    private function resource(User $user): array
    {
        return (null === $user->getScimExternalId() ? [] : ['externalId' => $user->getScimExternalId()]) + [
            'schemas' => [self::USER_SCHEMA], 'id' => (string) $user->getId(), 'userName' => $user->getEmail(),
            'name' => ['givenName' => $user->getFirstName(), 'familyName' => $user->getLastName(), 'formatted' => trim($user->getFirstName().' '.$user->getLastName())],
            'emails' => [['value' => $user->getEmail(), 'primary' => true]], 'active' => User::STATUS_ACTIVE === $user->getStatus(),
            'meta' => ['resourceType' => 'User'],
        ];
    }

    private function access(Request $request, ?string $extraScope = null): PlatformIntegration|JsonResponse
    {
        $authorization = (string) $request->headers->get('Authorization', '');
        $plain = 1 === preg_match('/^Bearer\s+(\S+)$/i', $authorization, $match) ? $match[1] : (string) $request->headers->get('X-RiskPilot-Key', '');
        $validInput = '' !== $plain && 256 >= strlen($plain) && 1 === preg_match('/^[A-Za-z0-9_-]+$/D', $plain);
        $item = $validInput ? $this->integrations->findApiKey(hash('sha256', $plain)) : null;
        $limiter = $this->serviceApiLimiter->create(hash('sha256', $item instanceof PlatformIntegration ? 'scim-key:'.$item->getId() : 'scim-invalid:'.($request->getClientIp() ?? 'unknown')))->consume();
        if (!$limiter->isAccepted()) {
            $response = $this->error(429, 'Trop de requêtes.');
            $response->headers->set('Retry-After', (string) max(1, $limiter->getRetryAfter()->getTimestamp() - time()));

            return $response;
        }
        if (!$item instanceof PlatformIntegration || !$item->verifies($plain) || $item->isCredentialExpired()) {
            return $this->error(401, 'Clé de service invalide ou expirée.');
        }
        $scopes = array_map('strval', (array) ($item->getConfiguration()['scopes'] ?? []));
        if (!in_array('scim:write', $scopes, true)) {
            return $this->error(403, 'La clé ne possède pas la portée scim:write.');
        }
        if (null !== $extraScope && !in_array($extraScope, $scopes, true)) {
            return $this->error(403, sprintf('La clé ne possède pas la portée %s.', $extraScope));
        }
        if ($item->markUsed()) {
            $this->entityManager->flush();
        }
        $request->attributes->set('_scim_integration', $item->getId());

        return $item;
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): JsonResponse
    {
        return new JsonResponse($payload, $status, ['Content-Type' => 'application/scim+json', 'Cache-Control' => 'no-store']);
    }

    private function error(int $status, string $detail, ?string $scimType = null): JsonResponse
    {
        return $this->json(['schemas' => [self::ERROR_SCHEMA], 'status' => (string) $status, 'detail' => $detail] + (null === $scimType ? [] : ['scimType' => $scimType]), $status);
    }
}
