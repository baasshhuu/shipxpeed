import { createHmac, randomBytes, scryptSync, timingSafeEqual } from 'node:crypto';

export const hashPassword = (pw: string, salt = randomBytes(16).toString('hex')) =>
  ({ salt, hash: scryptSync(pw, salt, 64).toString('hex') });

export function checkPassword(pw: string, salt: string, hash: string): boolean {
  const a = Buffer.from(scryptSync(pw, salt, 64).toString('hex')), b = Buffer.from(hash);
  return a.length === b.length && timingSafeEqual(a, b);
}

// Signed session token holding the user id and role. Valid for 12 hours.
export function makeTokens(secret: string) {
  const sign = (p: string) => createHmac('sha256', secret).update(p).digest('base64url');
  return {
    issue(uid: string, role: string) {
      const p = Buffer.from(JSON.stringify({ uid, role, exp: Date.now() + 12 * 3600_000 })).toString('base64url');
      return `${p}.${sign(p)}`;
    },
    verify(t: string | undefined): { uid: string; role: string } | null {
      if (!t) return null;
      const [p, s] = t.split('.');
      if (!p || !s) return null;
      const x = Buffer.from(s), y = Buffer.from(sign(p));
      if (x.length !== y.length || !timingSafeEqual(x, y)) return null;
      try { const d = JSON.parse(Buffer.from(p, 'base64url').toString()); return d.exp > Date.now() ? { uid: d.uid, role: d.role } : null; }
      catch { return null; }
    },
  };
}
