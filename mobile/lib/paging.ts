/** Laravel paginator meta as returned by every /api/v1 list endpoint. */
export type PageMeta = {
  current_page: number;
  last_page: number;
  per_page?: number;
  total?: number;
};

export function hasMorePages(meta: PageMeta | null | undefined): boolean {
  return Boolean(meta && meta.current_page < meta.last_page);
}

/** Append `page=N` to a path that may already carry a query string. */
export function withPage(path: string, page: number): string {
  return `${path}${path.includes('?') ? '&' : '?'}page=${page}`;
}

/** Merge a following page, skipping rows already shown (lists can shift between requests). */
export function appendUnique<T extends { id: number | string }>(current: T[], next: T[]): T[] {
  const seen = new Set(current.map((row) => row.id));
  return [...current, ...next.filter((row) => !seen.has(row.id))];
}
