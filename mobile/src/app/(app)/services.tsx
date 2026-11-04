import { useRouter } from 'expo-router';
import { Fragment } from 'react';

import { Screen } from '@/components/screen';
import { Divider, Panel, Section } from '@/components/section';
import { SettingsRow } from '@/components/settings-row';
import { FEATURE_GROUPS } from '@/lib/features';

/** Every service, as the web's menu lists them. */
export default function ServicesScreen() {
  const router = useRouter();

  return (
    <Screen back title="Semua layanan">
      {FEATURE_GROUPS.map((group) => (
        <Section key={group.title} label={group.title}>
          <Panel padded={false}>
            {group.features.map((feature, index) => (
              <Fragment key={feature.key}>
                {index > 0 ? <Divider /> : null}
                <SettingsRow
                  icon={feature.icon}
                  label={feature.title}
                  accessibilityHint={feature.description}
                  onPress={() => router.push(feature.href)}
                />
              </Fragment>
            ))}
          </Panel>
        </Section>
      ))}
    </Screen>
  );
}
