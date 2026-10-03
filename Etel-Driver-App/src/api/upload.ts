import Constants from 'expo-constants';
import { fetchWithTimeout, getToken } from './client';

// Photos are far larger than the JSON calls, so they get a longer limit.
const UPLOAD_TIMEOUT_MS = 60000;

// api/upload.php is a standalone endpoint (not a boot.php action) —
// multipart POST with Authorization: Bearer <token> and field `image`,
// returns { success, data: { url } }. Used for compliance document photos
// and manual top-up receipts.
const API_BASE_URL: string =
  (Constants.expoConfig?.extra?.etelApiBaseUrl as string) ||
  'https://ethelmarket.com/delivery/api/boot.php';
const UPLOAD_URL = API_BASE_URL.replace(/boot\.php$/, 'upload.php');

export async function uploadImage(localUri: string): Promise<string> {
  const token = await getToken();
  const form = new FormData();
  const filename = localUri.split('/').pop() || `photo_${Date.now()}.jpg`;
  const match = /\.(\w+)$/.exec(filename);
  const ext = match ? match[1].toLowerCase() : 'jpg';
  const mime = ext === 'png' ? 'image/png' : 'image/jpeg';

  // React Native's fetch/FormData accepts this shape (uri/name/type) for a
  // file part; it isn't standard web FormData typing hence the `as any`.
  form.append('image', { uri: localUri, name: filename, type: mime } as any);

  let res: Response;
  try {
    res = await fetchWithTimeout(
      UPLOAD_URL,
      {
        method: 'POST',
        headers: {
          Authorization: `Bearer ${token}`,
          // Content-Type intentionally omitted — fetch sets the multipart
          // boundary itself when the body is a FormData instance.
        },
        body: form,
      },
      UPLOAD_TIMEOUT_MS
    );
  } catch (e) {
    const timedOut = e instanceof Error && e.name === 'AbortError';
    throw new Error(
      timedOut ? 'The upload took too long. Try again on a stronger connection.' : 'No connection. Check your internet and try again.'
    );
  }

  const json = await res.json().catch(() => null);
  if (!res.ok || json?.success === false) {
    throw new Error(json?.message || `Upload failed (${res.status})`);
  }
  return json.data.url as string;
}
