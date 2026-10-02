import { promises as fs } from 'node:fs';
import path from 'node:path';

// Temporary JSON-file store so shipments survive restarts. Replace with PostgreSQL later.
// Writes are serialized so two requests cannot overwrite each other.

export interface ShipmentRecord {
  id: string;
  orderId: string;
  courier: string;
  awb: string;
  courierRef?: string;
  status: string;
  dryRun: boolean;
  createdAt: string;
  cancelledAt?: string;
  to: { name: string; phone: string; line1: string; city: string; state: string; pincode: string };
  parcel: { paymentMode: 'Prepaid' | 'COD'; declaredValue: number; codAmount?: number; weightGrams: number; productDescription: string; quantity: number };
}

export class Store {
  private file: string;
  private chain: Promise<unknown> = Promise.resolve();
  private cache: ShipmentRecord[] | null = null;

  constructor(dir: string) { this.file = path.join(dir, 'shipments.json'); }

  private async load(): Promise<ShipmentRecord[]> {
    if (this.cache) return this.cache;
    try { this.cache = JSON.parse(await fs.readFile(this.file, 'utf8')); }
    catch { this.cache = []; }
    return this.cache!;
  }

  // Runs fn with exclusive access, then saves.
  tx<T>(fn: (rows: ShipmentRecord[]) => Promise<T> | T): Promise<T> {
    const run = this.chain.then(async () => {
      const rows = await this.load();
      const out = await fn(rows);
      await fs.mkdir(path.dirname(this.file), { recursive: true });
      const tmp = this.file + '.tmp';
      await fs.writeFile(tmp, JSON.stringify(rows, null, 2));
      await fs.rename(tmp, this.file);
      return out;
    });
    this.chain = run.catch(() => undefined);
    return run;
  }

  async list(): Promise<ShipmentRecord[]> { await this.chain; return [...(await this.load())].reverse(); }
  async get(id: string) { await this.chain; return (await this.load()).find(r => r.id === id); }
}
