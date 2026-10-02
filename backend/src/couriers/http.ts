// Shared HTTP client for all courier adapters: timeout, exponential backoff
// on 429/5xx/network errors, and typed errors. Never logs headers or tokens.

export class CourierError extends Error {
  constructor(public status: number, message: string, public retryable: boolean, public body?: unknown) {
    super(message);
  }
}

export interface ReqOpts extends RequestInit { timeoutMs?: number; retries?: number }

// retries: use the default for idempotent reads (GET). Pass retries: 0 for
// anything that creates something (shipments, pickups) and let the job queue
// retry with an idempotency check, so a retry can never double-book.
export async function request(url: string, opts: ReqOpts = {}): Promise<Response> {
  const { timeoutMs = 15000, retries = 3, ...init } = opts;
  for (let attempt = 0; ; attempt++) {
    const ctl = new AbortController();
    const timer = setTimeout(() => ctl.abort(), timeoutMs);
    let err: CourierError;
    try {
      const res = await fetch(url, { ...init, signal: ctl.signal });
      if (res.ok) return res;
      const retryable = res.status === 429 || res.status >= 500;
      err = new CourierError(res.status, `Courier returned HTTP ${res.status}`, retryable, (await res.text().catch(() => '')).slice(0, 500));
    } catch (e) {
      err = new CourierError(0, (e as Error).name === 'AbortError' ? 'Courier request timed out' : 'Network failure', true);
    } finally {
      clearTimeout(timer);
    }
    if (!err.retryable || attempt >= retries) throw err;
    await new Promise(r => setTimeout(r, 2 ** attempt * 500 + Math.random() * 250));
  }
}
