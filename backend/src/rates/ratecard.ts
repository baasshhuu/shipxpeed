// Universal rate card logic. Every courier is priced with the same formula, so adding a courier
// means adding a rate card (data), not new code. All money is in rupees.

export type ZoneIndex = 0 | 1 | 2 | 3 | 4; // A same city, B same state, C metro to metro, D rest of India, E remote

export interface CourierRateCard {
  /** [first 500 g, each extra 500 g] per zone A..E, courier cost before GST */
  zones: [number, number][];
  /** COD fee = max(minimum rupees, percent of order value) */
  cod: { min: number; percent: number };
  /** RTO charge as a percent of forward freight */
  rtoPercent: number;
  /** Delivery days per zone A..E */
  etaDays: number[];
}

export interface Quote { freight: number; codFee: number; rto: number; gst: number; total: number }

export const GST_RATE = 0.18;

/** Chargeable weight in kg: larger of actual and volumetric (L x B x H / 5000). */
export function chargeableKg(actualKg: number, l = 0, b = 0, h = 0): number {
  return Math.max(actualKg, (l * b * h) / 5000);
}

/**
 * costOrPrice: pass marginPercent = 0 for the courier's cost, or the seller's margin for the seller price.
 * The margin is applied to freight, COD fee and RTO alike, so seller and cost views always stay consistent.
 */
export function quote(card: CourierRateCard, zone: ZoneIndex, chargeable: number, mode: 'Prepaid' | 'COD', orderValue: number, marginPercent = 0): Quote {
  if (!(chargeable > 0)) throw new Error('chargeable weight must be above 0');
  const [first, extra] = card.zones[zone];
  const slabs = Math.max(1, Math.ceil(chargeable / 0.5));
  const m = 1 + marginPercent / 100;
  const freight = (first + (slabs - 1) * extra) * m;
  const codFee = mode === 'COD' ? Math.max(card.cod.min, (orderValue * card.cod.percent) / 100) * m : 0;
  const rto = freight * (card.rtoPercent / 100);
  const gst = (freight + codFee) * GST_RATE;
  return { freight, codFee, rto, gst, total: freight + codFee + gst };
}

/** Margin for a seller: their own override for this courier if set, otherwise the courier default. */
export function effectiveMargin(defaultPercent: number, override?: number | null): number {
  return override === undefined || override === null ? defaultPercent : override;
}

const REMOTE_STATES = new Set(['Jammu & Kashmir', 'Ladakh', 'Arunachal Pradesh', 'Assam', 'Manipur', 'Meghalaya', 'Mizoram', 'Nagaland', 'Tripura', 'Sikkim', 'Andaman & Nicobar Islands', 'Lakshadweep']);
const METROS = ['delhi', 'mumbai', 'kolkata', 'chennai', 'bengaluru', 'hyderabad', 'pune', 'ahmedabad'];

export interface Place { city: string; district: string; state: string }
export function zoneFor(from: Place, to: Place): ZoneIndex {
  if (REMOTE_STATES.has(to.state)) return 4;
  if (from.district === to.district) return 0;
  if (from.state === to.state) return 1;
  const metro = (p: Place) => METROS.some(m => p.city.toLowerCase().includes(m) || p.district.toLowerCase().includes(m));
  return metro(from) && metro(to) ? 2 : 3;
}
