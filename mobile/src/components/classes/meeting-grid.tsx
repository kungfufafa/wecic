import { StyleSheet, View } from 'react-native';

import type { MeetingAttendance } from '@/api/types/classes';
import { ThemedText } from '@/components/themed-text';
import { Radius, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import { meetingStatus, type MeetingStatusKind } from '@/lib/classes';

/** Every meeting of a class as a numbered tile colored by attendance, with a legend under it. */
export function MeetingGrid({ meetings }: { meetings: MeetingAttendance[] }) {
  const theme = useTheme();
  const colors: Record<MeetingStatusKind, { background: string; text: string }> = {
    present: { background: theme.successTint, text: theme.success },
    late: { background: theme.warningTint, text: theme.warning },
    absent: { background: theme.dangerTint, text: theme.danger },
    permit: { background: theme.brandTint, text: theme.brand },
    sick: { background: theme.brandTint, text: theme.brand },
    upcoming: { background: theme.elevated, text: theme.textTertiary },
    unknown: { background: theme.elevated, text: theme.textQuaternary },
  };
  const shown = new Set(meetings.map((meeting) => meetingStatus(meeting.status).kind));
  const legend = (['present', 'late', 'absent', 'permit', 'sick', 'upcoming'] as const).filter((kind) => shown.has(kind));

  return (
    <View style={styles.wrap}>
      <View style={styles.grid}>
        {meetings.map((meeting, index) => {
          const status = meetingStatus(meeting.status);
          const color = colors[status.kind];
          const when = [meeting.details?.attendance_date, meeting.details?.attendance_time].filter(Boolean).join(', ');

          return (
            <View
              key={`${meeting.meeting_number ?? index}`}
              accessible
              accessibilityLabel={`Pertemuan ${meeting.meeting_number ?? index + 1}: ${status.label}${when ? `, ${when}` : ''}`}
              style={[styles.tile, { backgroundColor: color.background }]}>
              <ThemedText type="smallBold" style={{ color: color.text }}>
                {meeting.meeting_number ?? index + 1}
              </ThemedText>
            </View>
          );
        })}
      </View>
      <View style={styles.legend}>
        {legend.map((kind) => (
          <View key={kind} style={styles.legendItem}>
            <View style={[styles.dot, { backgroundColor: colors[kind].text }]} />
            <ThemedText type="caption" themeColor="textTertiary">
              {meetingStatus(kind).label}
            </ThemedText>
          </View>
        ))}
      </View>
    </View>
  );
}

const TILE = 40;

const styles = StyleSheet.create({
  wrap: {
    gap: Spacing.three,
  },
  grid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: Spacing.two,
  },
  tile: {
    width: TILE,
    height: TILE,
    borderRadius: Radius.small,
    alignItems: 'center',
    justifyContent: 'center',
  },
  legend: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: Spacing.three - Spacing.one,
  },
  legendItem: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.one + Spacing.half,
  },
  dot: {
    width: 8,
    height: 8,
    borderRadius: 4,
  },
});
