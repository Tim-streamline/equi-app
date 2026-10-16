// Compatibility for already delivered photo links; questions use the regular section screen.
import { Redirect } from 'expo-router';
import { useIntakeSchema } from '@/lib/intake/schema-provider';

export default function IntakePhotos() {
  const { schema } = useIntakeSchema();
  const section = schema.find(section => section.fields.some(field => field.type === 'photo'));
  return <Redirect href={section ? { pathname: '/intake/section/[id]', params: { id: section.id } } : '/intake/overview'} />;
}
