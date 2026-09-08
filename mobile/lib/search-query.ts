let pending = '';

export function setPendingSearchQuery(q: string): void {
  pending = q.trim();
}

export function consumePendingSearchQuery(): string {
  const value = pending;
  pending = '';
  return value;
}
