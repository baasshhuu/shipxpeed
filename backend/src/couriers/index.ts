import { DelhiveryAdapter } from './delhivery';
import { BigshipAdapter } from './bigship';
import type { CourierAdapter } from './types';

// Add a courier = write its adapter file + add one block here.
// Order management and the allocation engine only ever see CourierAdapter.
export function buildCouriers(env: NodeJS.ProcessEnv = process.env): Map<string, CourierAdapter> {
  const list: CourierAdapter[] = [];

  if (env.DELHIVERY_API_TOKEN) {
    list.push(new DelhiveryAdapter({
      token: env.DELHIVERY_API_TOKEN,
      env: env.DELHIVERY_ENV === 'production' ? 'production' : 'staging',
      pickupLocation: env.DELHIVERY_PICKUP_LOCATION ?? '',
      sellerGstTin: env.DELHIVERY_SELLER_GST_TIN,
      hsnCode: env.DELHIVERY_HSN_CODE,
      dryRun: env.DELHIVERY_DRY_RUN !== 'false', // safe by default
      baseUrl: env.DELHIVERY_BASE_URL || undefined, // tests only
    }));
  }
  if (env.BIGSHIP_USERNAME && env.BIGSHIP_PASSWORD && env.BIGSHIP_ACCESS_KEY) {
    list.push(new BigshipAdapter({
      username: env.BIGSHIP_USERNAME, password: env.BIGSHIP_PASSWORD, accessKey: env.BIGSHIP_ACCESS_KEY,
      pickupWarehouseId: Number(env.BIGSHIP_PICKUP_WAREHOUSE_ID ?? 0),
      returnWarehouseId: env.BIGSHIP_RETURN_WAREHOUSE_ID ? Number(env.BIGSHIP_RETURN_WAREHOUSE_ID) : undefined,
      pickupPincode: env.BIGSHIP_PICKUP_PINCODE ?? '',
      categoryId: env.BIGSHIP_CATEGORY_ID,
      dryRun: env.BIGSHIP_DRY_RUN !== 'false', // safe by default
    }));
  }
  // if (env.XPRESSBEES_...) list.push(new XpressbeesAdapter(...));

  return new Map(list.map(c => [c.code, c]));
}

export * from './types';
