import { assignmentTarget, classRouteOf, linkParams, meetingTitleParts, saysNoAssignment } from '@/lib/meetings';

describe('meetings', () => {
  it('reads the meeting and course from the breadcrumb', () => {
    expect(meetingTitleParts('Beranda > Kelas Manajemen Proyek >Pertemuan 3')).toEqual({ meeting: 'Pertemuan 3', course: 'Manajemen Proyek' });
    expect(meetingTitleParts(null)).toEqual({ meeting: null, course: null });
  });

  it('reads a portal link query', () => {
    expect(linkParams('?mod=sipandai&act=discuss&id=30061&courses_id=8987')).toEqual({ mod: 'sipandai', act: 'discuss', id: '30061', courses_id: '8987' });
    expect(linkParams('')).toEqual({});
    expect(linkParams(null)).toEqual({});
  });

  it('takes the upload target from the upload link', () => {
    expect(assignmentTarget({ uploadLink: '?mod=sipandai&act=upload&id=91&courses_id=8810&pertemuan=4' }, '1', '2')).toEqual({
      courses_id: '8810',
      id: '91',
      pertemuan: '4',
    });
  });

  it('else uses the meeting when it has assignment content and a number', () => {
    expect(assignmentTarget({ deadline: 'Batas waktu upload tugas Pertemuan 3', meetingNumber: '3' }, '8810', '30061')).toEqual({
      courses_id: '8810',
      id: '30061',
      pertemuan: '3',
    });
    expect(assignmentTarget({ assignmentTopic: 'Pertemuan 5' }, '8810', '30061')).toEqual({ courses_id: '8810', id: '30061', pertemuan: '5' });
  });

  it('has no target without content, a number, a course, or when the portal says there is no assignment', () => {
    expect(assignmentTarget({ meetingNumber: '3' }, '8810', '30061')).toBeNull();
    expect(assignmentTarget({ deadline: 'Kumpulkan sebelum Jumat' }, '8810', '30061')).toBeNull();
    expect(assignmentTarget({ deadline: 'x', meetingNumber: 3 }, null, '30061')).toBeNull();
    expect(
      assignmentTarget({ statusText: 'Tidak ada tugas perkuliahan untuk pertemuan tersebut', deadline: 'x', meetingNumber: '7' }, '8810', '1'),
    ).toBeNull();
  });

  it('knows the portal text for a meeting without an assignment', () => {
    expect(saysNoAssignment('Tidak ada tugas perkuliahan untuk pertemuan tersebut')).toBe(true);
    expect(saysNoAssignment('Batas waktu upload tugas')).toBe(false);
  });

  it('opens the class of a meeting', () => {
    expect(classRouteOf('/class/5413?dosen_id=2744&course_id=9521')).toEqual({ id: '5413', dosen_id: '2744', course_id: '9521' });
    expect(classRouteOf('/class/5413')).toEqual({ id: '5413' });
    expect(classRouteOf(null)).toBeNull();
  });
});
