// Preserve old deep links while the product is now a separately purchased protocol.
import { Redirect } from 'expo-router';
export default function LegacyProtocolLink() { return <Redirect href="/protocol-purchase" />; }
