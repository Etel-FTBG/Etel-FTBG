import { Alert } from 'react-native';

// Prominent disclosure (Google Play policy): shown before the background-location request, in plain words, with a clear Agree / Not now.
export const LOCATION_DISCLOSURE = {
  title: 'ETEL uses your location in the background',
  message:
    'ETEL collects your phone\'s location, also when the app is closed or not in use, while you are online as a driver, courier or service giver. ' +
    'It is used to show your position to the rider or customer on your trip, to send you nearby requests, and for dispatch and safety. ' +
    'It stops when you go offline. It is not used for advertising and is not sold.\n\n' +
    'On the next screen choose "Allow all the time".',
  agree: 'Agree and continue',
  decline: 'Not now',
};
export function showLocationDisclosure(): Promise<boolean> {
  return new Promise<boolean>((resolve) => {
    Alert.alert(
      LOCATION_DISCLOSURE.title,
      LOCATION_DISCLOSURE.message,
      [
        { text: LOCATION_DISCLOSURE.decline, style: 'cancel', onPress: () => resolve(false) },
        { text: LOCATION_DISCLOSURE.agree, onPress: () => resolve(true) },
      ],
      { cancelable: true, onDismiss: () => resolve(false) }
    );
  });
}
