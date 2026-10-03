import React, { createContext, useContext, useEffect, useState, useCallback } from 'react';
import { boot, getToken, onUnauthorized } from '../api/client';
import { DriverUser, loginDriver, logoutDriver } from '../api/auth';
import { stopDriverLocationTracking } from '../location/backgroundLocationTask';
import { registerForPushNotifications, unregisterPushNotifications } from '../notifications/registerPush';

interface AuthContextValue {
  user: DriverUser | null;
  loading: boolean;
  login: (identifier: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | undefined>(undefined);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<DriverUser | null>(null);
  const [loading, setLoading] = useState(true);

  const logout = useCallback(async () => {
    await stopDriverLocationTracking().catch(() => {});
    await unregisterPushNotifications().catch(() => {});
    await logoutDriver();
    setUser(null);
  }, []);

  useEffect(() => {
    // There's no refresh-token endpoint on this backend — a stored token is
    // validated on cold start by calling driver_get_profile; a 401 there
    // clears it via the onUnauthorized hook below.
    (async () => {
      const token = await getToken();
      if (token) {
        try {
          // driver_get_profile's `driver` comes from ride_drivers, a
          // different shape than login's `user` (user_id not id, no role,
          // plus rating/balance/vehicle fields) — RideController.php:2357.
          // Map explicitly rather than trusting the shape to line up.
          const res = await boot<{ driver: { user_id: number; name: string; phone: string } }>(
            'driver_get_profile',
            {}
          );
          setUser({ id: res.driver.user_id, name: res.driver.name, phone: res.driver.phone, role: 'driver' });
        } catch {
          // onUnauthorized already cleared the token on a real 401; any
          // other failure (e.g. offline on cold start) just leaves user null
          // and the login screen shows — safer than trusting a stale token.
          setUser(null);
        }
      }
      setLoading(false);
    })();

    onUnauthorized(() => {
      setUser(null);
    });
  }, []);

  const login = useCallback(async (identifier: string, password: string) => {
    const u = await loginDriver(identifier, password);
    setUser(u);
  }, []);

  // Registers (or re-registers) the Expo push token whenever a session
  // becomes active — covers both a fresh login and a cold-start restore.
  // Non-fatal if it fails: the app still works via the existing polling.
  useEffect(() => {
    if (user) registerForPushNotifications().catch(() => {});
  }, [user]);

  return (
    <AuthContext.Provider value={{ user, loading, login, logout }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within AuthProvider');
  return ctx;
}
