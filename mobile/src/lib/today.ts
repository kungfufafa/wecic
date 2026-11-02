import type { Href } from 'expo-router';

import type { TodayClass } from '@/api/types';
import { classRouteOf } from '@/lib/meetings';

/** Where a class on today's schedule opens: its meeting once known, else the class. */
export function todayClassRoute(todayClass: TodayClass): Href | null {
  const payload = todayClass.attendance?.payload;

  if (payload?.modulesid) {
    return {
      pathname: '/meeting/[id]',
      params: { id: String(payload.modulesid), ...(payload.courseid ? { courses_id: String(payload.courseid) } : {}) },
    };
  }

  const classRoute = classRouteOf(todayClass.attendance?.class_url);

  return classRoute ? { pathname: '/class/[id]', params: classRoute } : null;
}

/** "Hari ini", "Besok", "3 hari lagi", "Lewat 2 hari" for an action item. */
export function daysLeftLabel(daysLeft: number | null | undefined): string | null {
  if (daysLeft === null || daysLeft === undefined || !Number.isFinite(daysLeft)) {
    return null;
  }

  if (daysLeft < 0) {
    return `Lewat ${Math.abs(daysLeft)} hari`;
  }

  if (daysLeft === 0) {
    return 'Hari ini';
  }

  return daysLeft === 1 ? 'Besok' : `${daysLeft} hari lagi`;
}
