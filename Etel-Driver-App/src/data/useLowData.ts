import { useEffect, useState } from 'react';
import { isLowData, subscribeLowData } from './lowData';

// True while the "Save mobile data" switch is on; the screen redraws when it changes.
export function useLowData(): boolean {
  const [on, setOn] = useState(isLowData());
  useEffect(() => subscribeLowData(setOn), []);
  return on;
}
