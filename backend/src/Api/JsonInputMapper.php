<?php

declare(strict_types=1);

namespace App\Api;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class JsonInputMapper
{
    public function __construct(private SerializerInterface $serializer, private ValidatorInterface $validator)
    {
    }

    /** @template T of object
     * @param class-string<T> $type
     *
     * @return array{0: T, 1: ConstraintViolationListInterface}
     */
    public function map(Request $request, string $type): array
    {
        try {
            $input = $this->serializer->deserialize($request->getContent(), $type, 'json');
        } catch (\Throwable) {
            throw new BadRequestHttpException('Le corps JSON est invalide.');
        }

        return [$input, $this->validator->validate($input)];
    }
}
