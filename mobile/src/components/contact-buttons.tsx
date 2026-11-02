import { Linking, StyleSheet, View } from 'react-native';

import { Chip } from '@/components/chip';
import { Spacing } from '@/constants/theme';
import { phoneDigits, whatsappNumber } from '@/lib/contact';

export type Contact = {
  phone?: string | null;
  whatsapp?: string | null;
  email?: string | null;
};

/** Call, WhatsApp and email chips for a lecturer or DPA; nothing when there is no way to reach them. */
export function ContactButtons({ contact }: { contact: Contact }) {
  const phone = phoneDigits(contact.phone);
  const whatsapp = whatsappNumber(contact.whatsapp ?? contact.phone);
  const email = contact.email?.trim() || null;

  if (!phone && !whatsapp && !email) {
    return null;
  }

  return (
    <View style={styles.row}>
      {whatsapp ? <Chip icon="chat" label="WhatsApp" onPress={() => void Linking.openURL(`https://wa.me/${whatsapp}`)} /> : null}
      {phone ? <Chip icon="call" label="Telepon" onPress={() => void Linking.openURL(`tel:${phone}`)} /> : null}
      {email ? <Chip icon="mail" label="Email" onPress={() => void Linking.openURL(`mailto:${email}`)} /> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: Spacing.two,
  },
});
