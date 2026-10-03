import { boot, setToken, clearToken } from './client';

export interface DriverUser {
  id: number;
  name: string;
  phone: string;
  role: string;
}

interface LoginResponse {
  token: string;
  user: DriverUser;
  etel_id?: string;
  sso?: boolean;
  sso_token?: string | null;
}

// Every Etel Group system here signs in via EtelID SSO, not a locally-set
// per-system password — AuthController.php:325 (`etelid_login`), which
// verifies against EtelID itself (server-to-server) and finds-or-creates
// the local driver account from the result. The plain `login` action
// (AuthController.php:170) checks a separate, locally-stored password that
// isn't the one anyone actually uses here.
export async function loginDriver(identifier: string, password: string): Promise<DriverUser> {
  const res = await boot<LoginResponse>(
    'etelid_login',
    { identifier, password, role: 'driver' },
    { auth: false }
  );
  await setToken(res.token);
  return res.user;
}

export async function logoutDriver(): Promise<void> {
  await clearToken();
}
