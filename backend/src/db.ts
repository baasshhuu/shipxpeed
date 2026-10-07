import { promises as fs } from 'node:fs';
import path from 'node:path';

// Account database kept in one JSON file (data/db.json) so it survives restarts.
// Every change runs one at a time and is written to disk before the request answers.
// Fine for the first sellers on one server. Move to MySQL/PostgreSQL before real scale.

export interface User {
  id: string; email: string; role: 'seller' | 'admin'; name: string; city: string; mobile: string;
  salt: string; hash: string; active: boolean; createdAt: string;
}
export interface SellerState {
  orders: unknown[][]; ndr: unknown[][]; ledger: [string, string, number, number, string?][];
  kyc: { status: string; flags: Record<string, number> }; docs: Record<string, number>;
}
export interface Data {
  users: User[];
  states: Record<string, SellerState>;
  cards: Record<string, Record<string, unknown>>; // per client, per courier: rate card + live flag
  cost: { c: Record<string, unknown>; m: Record<string, number> } | null; // courier cost cards and default margins
}

export const blankState = (): SellerState => ({ orders: [], ndr: [], ledger: [], kyc: { status: 'Not started', flags: {} }, docs: {} });

export class Db {
  private file: string;
  private chain: Promise<unknown> = Promise.resolve();
  private cache: Data | null = null;
  constructor(dir: string) { this.file = path.join(dir, 'db.json'); }

  private async load(): Promise<Data> {
    if (this.cache) return this.cache;
    try { this.cache = { users: [], states: {}, cards: {}, cost: null, ...JSON.parse(await fs.readFile(this.file, 'utf8')) }; }
    catch (e) {
      if ((e as NodeJS.ErrnoException).code !== 'ENOENT') throw new Error('db.json exists but cannot be read. Fix or restore it, the server will not overwrite it.');
      this.cache = { users: [], states: {}, cards: {}, cost: null };
    }
    return this.cache!;
  }

  tx<T>(fn: (d: Data) => Promise<T> | T): Promise<T> {
    const run = this.chain.then(async () => {
      const d = await this.load();
      const snapshot = JSON.stringify(d);
      let out: T;
      try { out = await fn(d); }
      catch (e) { this.cache = JSON.parse(snapshot); throw e; } // a failed request leaves nothing half-changed
      await fs.mkdir(path.dirname(this.file), { recursive: true });
      const tmp = this.file + '.tmp';
      await fs.writeFile(tmp, JSON.stringify(d));
      await fs.rename(tmp, this.file);
      return out;
    });
    this.chain = run.catch(() => undefined);
    return run;
  }

  async read<T>(fn: (d: Data) => T): Promise<T> { await this.chain; return fn(await this.load()); }
}
