import { ActivityIndicator } from 'react-native';

import type { ClassesResponse } from '@/api/types';
import { Card } from '@/components/card';
import { Notice } from '@/components/notice';
import { Screen } from '@/components/screen';
import { ThemedText } from '@/components/themed-text';
import { useApiResource } from '@/hooks/use-api-resource';
import { classMeta } from '@/lib/format';

export default function ClassesScreen() {
  const classes = useApiResource<ClassesResponse>('class');
  const items = classes.data?.classes ?? [];

  return (
    <Screen
      title="Kelas"
      subtitle={items.length > 0 ? `${items.length} mata kuliah semester ini` : null}
      refreshing={classes.isRefreshing}
      onRefresh={classes.refresh}>
      {classes.error ? <Notice tone="error" message={classes.error} /> : null}
      {classes.isLoading ? <ActivityIndicator /> : null}

      {!classes.isLoading && items.length === 0 && !classes.error ? (
        <Card>
          <ThemedText type="small" themeColor="textSecondary">
            Belum ada kelas di semester ini.
          </ThemedText>
        </Card>
      ) : null}

      {items.map((portalClass, index) => {
        const meta = classMeta(portalClass);

        return (
          <Card key={portalClass.join_link?.params?.id ?? `${portalClass.course_info?.code ?? 'class'}-${index}`}>
            <ThemedText type="smallBold">{portalClass.course_info?.name ?? 'Mata kuliah'}</ThemedText>
            {portalClass.course_info?.code ? (
              <ThemedText type="caption" themeColor="textTertiary">
                {portalClass.course_info.code}
              </ThemedText>
            ) : null}
            {meta ? (
              <ThemedText type="small" themeColor="textSecondary">
                {meta}
              </ThemedText>
            ) : null}
            {portalClass.lecturer_info?.name ? (
              <ThemedText type="caption" themeColor="textTertiary">
                {portalClass.lecturer_info.name}
              </ThemedText>
            ) : null}
          </Card>
        );
      })}
    </Screen>
  );
}
