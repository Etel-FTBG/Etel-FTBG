// The words on the pills of the golden light depend on what the account does, so a customer sees "Your trip" and a driver sees "New request".
export function labelsFor(role: string | undefined): [string, string, string, string] {
  switch (role) {
    case 'driver':
      return ['Trip', 'New request', 'Order', 'Delivery'];
    case 'courier':
      return ['Trip', 'New request', 'New order', 'Delivery'];
    case 'service_giver':
      return ['Trip', 'Request', 'New order', 'In progress'];
    default:
      return ['Your trip', 'Request', 'Order', 'Your order'];
  }
}
