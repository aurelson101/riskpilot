export function hasAnyRole(
  roles: readonly string[] | undefined,
  allowedRoles: readonly string[],
): boolean {
  return Boolean(roles?.some((role) => allowedRoles.includes(role)));
}
