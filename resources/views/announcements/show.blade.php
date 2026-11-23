@extends('layouts.app')

@section('title', 'Detail Pengumuman')
@section('page_id', 'announcement-show')

@section('content')
    {{-- The plain <x-page-hero> header, written out so the back link keeps its data-announcement-back hook; the script fills #announcement-hero. --}}
    <header class="mb-6" data-slot="page-hero">
        <nav class="mb-2" aria-label="Navigasi pengumuman">
            <x-back-link :href="route('announcement.index')" data-announcement-back>Semua pengumuman</x-back-link>
        </nav>

        <div class="min-w-0" id="announcement-hero">
            <div class="flex flex-col gap-2">
                <x-skeleton class="h-3.5 w-24" />
                <x-skeleton class="h-7 w-4/5" />
                <x-skeleton class="h-4 w-1/2" />
            </div>
        </div>
    </header>

    <div id="announcement-detail" data-announcement-id="{{ $id }}" aria-busy="true">
        <span class="sr-only">Memuat pengumuman…</span>
        <div class="grid max-w-3xl gap-6 xl:max-w-none xl:grid-cols-[minmax(0,1fr)_22rem] xl:items-start xl:gap-8">
            <x-card>
                <x-card.body class="flex flex-col gap-2.5">
                    <x-skeleton class="h-3.5 w-full" />
                    <x-skeleton class="h-3.5 w-11/12" />
                    <x-skeleton class="h-3.5 w-4/5" />
                    <x-skeleton class="h-3.5 w-2/3" />
                </x-card.body>
            </x-card>
            <x-card>
                <x-card.body class="flex flex-col gap-3">
                    <x-skeleton class="h-12 w-full" />
                    <x-skeleton class="h-12 w-full" />
                </x-card.body>
            </x-card>
        </div>
    </div>

    <div id="announcement-sticky"></div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const app = window.portalApp;
            const host = document.getElementById('announcement-detail');
            const heroHost = document.getElementById('announcement-hero');
            const stickyHost = document.getElementById('announcement-sticky');
            const announcementId = host.dataset.announcementId;
            const detailUrl = `/api/announcement/${announcementId}`;
            const READ_KEY = 'mycic-announcement-read';
            const STEPS_KEY = 'mycic-announcement-steps';
            const DEADLINE_TYPES = { deadline: 'Batas akhir', period: 'Periode', date: 'Tanggal penting' };
            const ALLOWED_TAGS = new Set(['P', 'BR', 'B', 'STRONG', 'I', 'EM', 'U', 'UL', 'OL', 'LI', 'A', 'TABLE', 'THEAD', 'TBODY', 'TFOOT', 'TR', 'TD', 'TH', 'SPAN', 'SMALL']);
            const DROPPED_TAGS = new Set(['SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED', 'FORM', 'INPUT', 'BUTTON', 'SELECT', 'TEXTAREA', 'IMG', 'SVG', 'MATH', 'LINK', 'META', 'TEMPLATE', 'VIDEO', 'AUDIO']);
            const ICONS = {
                form: '<svg xmlns="http://www.w3.org/2000/svg" class="size-5" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 5h-2a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-12a2 2 0 0 0 -2 -2h-2" /><path d="M9 3m0 2a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v0a2 2 0 0 1 -2 2h-2a2 2 0 0 1 -2 -2z" /><path d="M9 12h6" /><path d="M9 16h6" /></svg>',
                file: '<svg xmlns="http://www.w3.org/2000/svg" class="size-5" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3v4a1 1 0 0 0 1 1h4" /><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z" /><path d="M12 11v6" /><path d="M9.5 14.5l2.5 2.5l2.5 -2.5" /></svg>',
                link: '<svg xmlns="http://www.w3.org/2000/svg" class="size-5" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 6h-6a2 2 0 0 0 -2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-6" /><path d="M11 13l9 -9" /><path d="M15 4h5v5" /></svg>',
                mail: '<svg xmlns="http://www.w3.org/2000/svg" class="size-5" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v10a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-10z" /><path d="M3 7l9 6l9 -6" /></svg>',
                calendar: '<svg xmlns="http://www.w3.org/2000/svg" class="size-5" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-12z" /><path d="M16 3v4" /><path d="M8 3v4" /><path d="M4 11h16" /><path d="M12 15h1v3" /></svg>',
                copy: '<svg xmlns="http://www.w3.org/2000/svg" class="size-4" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 7m0 2.667a2.667 2.667 0 0 1 2.667 -2.667h8.666a2.667 2.667 0 0 1 2.667 2.667v8.666a2.667 2.667 0 0 1 -2.667 2.667h-8.666a2.667 2.667 0 0 1 -2.667 -2.667z" /><path d="M4.012 16.737a2.005 2.005 0 0 1 -1.012 -1.737v-10c0 -1.1 .9 -2 2 -2h10c.75 0 1.158 .385 1.5 1" /></svg>',
                fileOff: '<svg xmlns="http://www.w3.org/2000/svg" class="size-5" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18" /><path d="M7 3h7l5 5v7m0 4a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-14" /></svg>',
            };
            let rendered = false;
            let current = null;

            const storage = {
                read(key) {
                    try {
                        const raw = window.localStorage.getItem(key);

                        return raw ? JSON.parse(raw) : null;
                    } catch (error) {
                        return null;
                    }
                },
                write(key, value) {
                    try {
                        window.localStorage.setItem(key, JSON.stringify(value));
                    } catch (error) {
                        // Read marks and checklists are a per-device convenience.
                    }
                },
            };

            const shortDate = (value) => app.formatIndonesianDate(value, { month: 'short', year: false });

            // A mark saved under the old wording-based id (before stable ids) moves to the stable id.
            const markRead = (item) => {
                const stored = storage.read(READ_KEY);
                const { ids, changed } = app.migrateAnnouncementReadIds(stored?.ids, [item]);

                if (ids.includes(item.id) && !changed) {
                    return;
                }

                if (!ids.includes(item.id)) {
                    ids.push(item.id);
                }

                storage.write(READ_KEY, { ids: ids.slice(-400), seeded: stored?.seeded === true });
            };

            const linkHost = (url) => {
                try {
                    return new URL(url).host.replace(/^www\./, '');
                } catch (error) {
                    return '';
                }
            };

            const fileName = (link) => {
                const fromUrl = String(link.url || '').split(/[?#]/)[0].split('/').pop();

                try {
                    return decodeURIComponent(fromUrl || '') || link.label || 'berkas';
                } catch (error) {
                    return fromUrl || link.label || 'berkas';
                }
            };

            const linksOf = (item) => (Array.isArray(item.links) ? item.links : [])
                .filter((link) => link && app.safeHref(link.url))
                .map((link) => ({
                    ...link,
                    href: link.is_campus_file ? app.buildCampusDownloadUrl(link.url, fileName(link)) : link.url,
                }));

            const deadlinesOf = (item) => (Array.isArray(item.deadlines) ? item.deadlines : [])
                .filter((deadline) => app.toDate(deadline?.date))
                .map((deadline) => ({
                    ...deadline,
                    days: app.daysUntil(deadline.date),
                    startDays: app.daysUntil(deadline.start),
                }));

            const deadlineTone = (deadline) => {
                if (deadline.days < 0) {
                    return 'secondary';
                }

                return deadline.days <= 3 ? 'danger' : (deadline.days <= 7 ? 'warning' : 'info');
            };

            const deadlineRange = (deadline) => (deadline.start && deadline.start !== deadline.date
                ? `${shortDate(deadline.start)} – ${app.formatIndonesianDate(deadline.date, { month: 'short' })}`
                : app.formatIndonesianDate(deadline.date, { month: 'short' }));

            const deadlineRelative = (deadline) => {
                if (deadline.days < 0) {
                    return 'sudah lewat';
                }

                if (deadline.startDays !== null && deadline.startDays <= 0) {
                    return `berlangsung, berakhir ${app.formatRelativeDays(deadline.date)}`;
                }

                if (deadline.startDays !== null && deadline.startDays > 0) {
                    return `mulai ${app.formatRelativeDays(deadline.start)}`;
                }

                return app.formatRelativeDays(deadline.date);
            };

            // Below xl the header shows the nearest deadline; from xl the aside lists them all, so the header drops it.
            const renderDeadlineChip = (deadlines) => {
                const nearest = [...deadlines]
                    .sort((first, second) => (first.days < 0) - (second.days < 0) || first.days - second.days)[0];

                if (!nearest) {
                    return '';
                }

                return `<p class="max-w-full xl:hidden [&>span]:whitespace-normal">${app.renderBadge(`${deadlineRange(nearest)} · ${deadlineRelative(nearest)}`, deadlineTone(nearest))}</p>`;
            };

            const renderHeader = (item, deadlines) => {
                const publishedDate = item.published_date
                    ? app.formatIndonesianDate(item.published_date, { weekday: true })
                    : (item.published_label || 'Tanggal tidak terbaca');
                const days = app.daysUntil(item.published_date);
                const relative = days !== null && days < 0 && days > -31 ? ` · ${days === -1 ? 'kemarin' : `${Math.abs(days)} hari lalu`}` : '';
                const publisher = [item.publisher, item.publisher_unit].filter(Boolean).join(' · ');

                return `
                    <div class="flex min-w-0 flex-col gap-2">
                        <p class="text-[13px] font-medium text-ink-muted">${app.escapeHtml(item.category_label || 'Umum')}</p>
                        <h1 class="${app.ui.pageHeroTitle} wrap-break-word" id="announcement-title">${app.escapeHtml(item.title || 'Pengumuman')}</h1>
                        <dl class="flex flex-wrap gap-x-1.5 gap-y-1 text-sm text-ink-muted">
                            <div>
                                <dt class="sr-only">Tanggal terbit</dt>
                                <dd>${app.escapeHtml(publishedDate)}${app.escapeHtml(relative)}</dd>
                            </div>
                            ${publisher ? `
                                <div class="min-w-0 before:me-1.5 before:content-['·']">
                                    <dt class="sr-only">Penerbit</dt>
                                    <dd class="inline wrap-break-word">${app.escapeHtml(publisher)}</dd>
                                </div>
                            ` : ''}
                        </dl>
                        ${renderDeadlineChip(deadlines)}
                        ${item.duplicate_of ? `
                            <p class="text-xs text-ink-subtle">
                                Isinya mirip pengumuman lain yang lebih baru.
                                <a href="/announcement/${encodeURIComponent(item.duplicate_of)}" class="${app.ui.link}">Buka versi terbaru</a>
                            </p>
                        ` : ''}
                    </div>
                `;
            };

            // Without an announcement to show, the hero keeps a plain title instead of its skeleton.
            const renderHeroFallback = () => {
                heroHost.innerHTML = `<h1 class="${app.ui.pageHeroTitle}" id="announcement-title">Detail pengumuman</h1>`;
            };

            // A card header's title: text only.
            const cardHeading = (id, title) => `<h2 class="${app.ui.cardTitle}" id="${id}">${title}</h2>`;

            // Ticks saved under the old wording-based id (before stable ids) move to the stable id.
            const readSteps = (item) => {
                const { record, changed } = app.migrateAnnouncementRecord(storage.read(STEPS_KEY), item);

                if (changed) {
                    storage.write(STEPS_KEY, record);
                }

                const done = Object.hasOwn(record, item.id) ? record[item.id] : null;

                return Array.isArray(done) ? done.map(Number) : [];
            };

            const writeSteps = (id, numbers) => {
                const stored = storage.read(STEPS_KEY);
                const all = stored && typeof stored === 'object' && !Array.isArray(stored) ? stored : {};

                delete all[id];
                all[id] = numbers;

                const keys = Object.keys(all);

                keys.slice(0, Math.max(0, keys.length - 40)).forEach((key) => delete all[key]);
                storage.write(STEPS_KEY, all);
            };

            const renderSteps = (item) => {
                const steps = (Array.isArray(item.steps) ? item.steps : []).filter((step) => step && step.text);

                if (steps.length < 2) {
                    return '';
                }

                const done = new Set(readSteps(item));

                return `
                    <section class="${app.ui.card}" data-slot="card" aria-labelledby="announcement-steps-title">
                        <div class="${app.ui.cardHeader} flex-wrap">
                            ${cardHeading('announcement-steps-title', 'Langkah yang perlu dilakukan')}
                            <span class="text-xs text-ink-subtle tabular-nums" data-steps-progress aria-live="polite"></span>
                        </div>
                        <ol class="${app.ui.list}">
                            ${steps.map((step, index) => {
                                const number = Number(step.number) || index + 1;

                                return `
                                    <li>
                                        <label class="flex min-h-11 cursor-pointer items-start gap-3 px-4 py-3 transition-colors hover:bg-surface/60 has-[:checked]:opacity-70 sm:px-5">
                                            <input type="checkbox" class="mt-0.5 size-5 shrink-0 cursor-pointer accent-brand" data-step="${number}" ${done.has(number) ? 'checked' : ''}>
                                            <span class="mt-0.5 w-5 shrink-0 text-sm text-ink-subtle tabular-nums" aria-hidden="true">${number}.</span>
                                            <span class="min-w-0 text-sm text-ink wrap-break-word">${app.escapeHtml(step.text)}</span>
                                        </label>
                                    </li>
                                `;
                            }).join('')}
                        </ol>
                    </section>
                `;
            };

            const bindSteps = (item) => {
                const boxes = Array.from(host.querySelectorAll('[data-step]'));
                const progress = host.querySelector('[data-steps-progress]');

                if (!boxes.length || !progress) {
                    return;
                }

                const update = () => {
                    const checked = boxes.filter((box) => box.checked).map((box) => Number(box.dataset.step));

                    progress.textContent = checked.length === boxes.length
                        ? 'Semua langkah sudah dicentang'
                        : `${checked.length} dari ${boxes.length} langkah dicentang`;

                    return checked;
                };

                update();
                boxes.forEach((box) => box.addEventListener('change', () => writeSteps(item.id, update())));
            };

            const buildBody = (item, links) => {
                const campusFiles = new Map(links.filter((link) => link.is_campus_file).map((link) => [link.url, link.href]));
                const template = document.createElement('template');

                template.innerHTML = String(item.body_html || '');

                Array.from(template.content.querySelectorAll('*')).forEach((element) => {
                    const tag = element.tagName;

                    if (DROPPED_TAGS.has(tag)) {
                        element.remove();

                        return;
                    }

                    if (!ALLOWED_TAGS.has(tag)) {
                        element.replaceWith(...element.childNodes);

                        return;
                    }

                    Array.from(element.attributes).forEach((attribute) => {
                        const name = attribute.name.toLowerCase();
                        const keep = (tag === 'A' && name === 'href') || ((tag === 'TD' || tag === 'TH') && (name === 'colspan' || name === 'rowspan'));

                        if (!keep) {
                            element.removeAttribute(attribute.name);
                        }
                    });

                    if (tag !== 'A') {
                        return;
                    }

                    const href = app.safeHref(element.getAttribute('href'));

                    if (!href) {
                        element.replaceWith(...element.childNodes);

                        return;
                    }

                    const proxied = campusFiles.get(href) || null;

                    element.setAttribute('href', proxied || href);

                    if (proxied || app.isExternalHref(href)) {
                        element.setAttribute('target', '_blank');
                        element.setAttribute('rel', 'noopener noreferrer');
                    }
                });

                template.content.querySelectorAll('table').forEach((table) => {
                    const wrapper = document.createElement('div');

                    wrapper.className = 'mb-3 overflow-x-auto rounded-lg border border-border last:mb-0';
                    table.replaceWith(wrapper);
                    wrapper.appendChild(table);
                });

                if (template.content.textContent.trim() !== '') {
                    return template.content;
                }

                const fallback = document.createDocumentFragment();

                String(item.body_text || '').split('\n').map((line) => line.trim()).filter(Boolean).forEach((line) => {
                    const paragraph = document.createElement('p');

                    paragraph.textContent = line;
                    fallback.appendChild(paragraph);
                });

                if (!fallback.childNodes.length) {
                    const empty = document.createElement('p');

                    empty.className = 'text-ink-subtle';
                    empty.textContent = 'Isi pengumuman tidak terbaca dari portal.';
                    fallback.appendChild(empty);
                }

                return fallback;
            };

            const ACTION_ROW = 'flex min-h-12 w-full cursor-pointer items-center gap-3 px-4 py-3 text-start text-sm transition-colors hover:bg-surface/60 focus-visible:bg-surface/60 focus-visible:outline-none sm:px-5';

            const actionRow = ({ href, icon, title, detail, external = false, onlineRequired = false, attribute = '' }) => `
                <a href="${app.escapeHtml(href)}" class="${ACTION_ROW}"${external ? ' target="_blank" rel="noopener noreferrer"' : ''}${onlineRequired ? ' data-online-required' : ''}${attribute ? ` ${attribute}` : ''}>
                    <span class="${app.ui.iconTile.neutral} size-9" aria-hidden="true">${icon}</span>
                    <span class="min-w-0 flex-1">
                        <span class="block font-medium text-ink">${app.escapeHtml(title)}</span>
                        ${detail ? `<span class="block truncate text-xs text-ink-subtle">${app.escapeHtml(detail)}</span>` : ''}
                    </span>
                </a>
            `;

            const linkAction = (link) => {
                if (link.is_campus_file) {
                    return { href: link.href, icon: ICONS.file, title: `Unduh ${link.label || 'berkas'}`, detail: 'Berkas kampus', external: true, onlineRequired: true };
                }

                if (link.kind === 'form') {
                    return { href: link.href, icon: ICONS.form, title: 'Buka formulir', detail: `${linkHost(link.url) || link.label} · tab baru`, external: true };
                }

                return {
                    href: link.href,
                    icon: link.kind === 'file' ? ICONS.file : ICONS.link,
                    title: link.kind === 'file' ? `Buka ${link.label || 'berkas'}` : (link.label && link.label !== linkHost(link.url) ? link.label : 'Buka tautan'),
                    detail: `${linkHost(link.url)} · tab baru`,
                    external: app.isExternalHref(link.href),
                };
            };

            const futureDeadlines = (deadlines) => deadlines.filter((deadline) => deadline.days !== null && deadline.days >= 0);

            const renderActions = (item, links, deadlines) => {
                const emails = (Array.isArray(item.emails) ? item.emails : []).filter((email) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(email)));
                const upcoming = futureDeadlines(deadlines);
                const rows = [
                    ...links.map((link) => actionRow(linkAction(link))),
                    ...emails.map((email) => `
                        <div>
                            ${actionRow({ href: `mailto:${email}`, icon: ICONS.mail, title: 'Kirim email', detail: email })}
                            <div class="flex flex-wrap gap-2 pb-3 pe-4 ps-16 sm:pe-5 sm:ps-17">
                                <button type="button" class="${app.buttonClass('secondary', { small: true })}" data-copy-text="${app.escapeHtml(email)}" data-copy-label="Email">${ICONS.copy}Salin alamat email</button>
                            </div>
                        </div>
                    `),
                    upcoming.length ? `
                        <button type="button" class="${ACTION_ROW}" data-add-calendar>
                            <span class="${app.ui.iconTile.neutral} size-9" aria-hidden="true">${ICONS.calendar}</span>
                            <span class="min-w-0 flex-1">
                                <span class="block font-medium text-ink">Tambah ke kalender</span>
                                <span class="block truncate text-xs text-ink-subtle">${upcoming.length} tenggat (.ics)</span>
                            </span>
                        </button>
                    ` : '',
                ].filter(Boolean);
                const categoryLink = item.category
                    ? `<a href="/announcement?kategori=${encodeURIComponent(item.category)}" class="${app.ui.link} inline-flex min-h-11 items-center text-sm sm:min-h-8">Lihat pengumuman ${app.escapeHtml(item.category_label || 'sejenis')} lainnya</a>`
                    : '';

                return `
                    <section class="${app.ui.card}" data-slot="card" aria-labelledby="announcement-actions-title">
                        <div class="${app.ui.cardHeader}">
                            ${cardHeading('announcement-actions-title', 'Tautan & tindakan')}
                        </div>
                        ${rows.length ? `<div class="${app.ui.list}">${rows.join('')}</div>` : `<p class="${app.ui.cardBody} text-sm text-ink-subtle">Tidak ada tautan.</p>`}
                        ${categoryLink ? `<div class="${app.ui.cardFooter}">${categoryLink}</div>` : ''}
                    </section>
                `;
            };

            const renderDeadlineCard = (deadlines) => {
                if (!deadlines.length) {
                    return '';
                }

                const sorted = [...deadlines].sort((first, second) => (first.days < 0) - (second.days < 0) || first.days - second.days);

                return `
                    <section class="${app.ui.card}" data-slot="card" aria-labelledby="announcement-deadline-card-title">
                        <div class="${app.ui.cardHeader}">
                            ${cardHeading('announcement-deadline-card-title', 'Tenggat & tanggal penting')}
                        </div>
                        <ul class="${app.ui.cardBody} flex flex-col">
                            ${sorted.map((deadline) => `
                                <li class="flex flex-col gap-1.5 border-b border-border-light py-3 first:pt-0 last:border-b-0 last:pb-0">
                                    <span class="flex flex-wrap items-center justify-between gap-2">
                                        <span class="text-sm font-medium leading-snug text-ink">${app.escapeHtml(DEADLINE_TYPES[deadline.type] || 'Tenggat')}: ${app.escapeHtml(deadlineRange(deadline))}</span>
                                        ${app.renderBadge(app.capitalize(deadlineRelative(deadline)), deadlineTone(deadline))}
                                    </span>
                                    ${deadline.context ? `<span class="text-xs text-ink-subtle wrap-break-word">${app.escapeHtml(deadline.context)}</span>` : ''}
                                </li>
                            `).join('')}
                        </ul>
                    </section>
                `;
            };

            const pad = (number) => String(number).padStart(2, '0');
            const icsDate = (date) => `${date.getFullYear()}${pad(date.getMonth() + 1)}${pad(date.getDate())}`;
            const icsText = (value) => String(value ?? '').replace(/\\/g, '\\\\').replace(/;/g, '\\;').replace(/,/g, '\\,').replace(/\r?\n/g, '\\n');
            const icsFold = (line) => (line.length <= 73 ? line : line.match(/.{1,73}/g).join('\r\n '));

            const buildIcs = (item, deadlines) => {
                const stamp = new Date().toISOString().replace(/[-:]/g, '').replace(/\.\d{3}/, '');
                const lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//WeCIC//Pengumuman//ID', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH'];

                deadlines.forEach((deadline, index) => {
                    const end = app.toDate(deadline.date);
                    const start = app.toDate(deadline.start) || end;
                    const endExclusive = new Date(end.getFullYear(), end.getMonth(), end.getDate() + 1);
                    const label = DEADLINE_TYPES[deadline.type] || 'Tenggat';

                    lines.push(
                        'BEGIN:VEVENT',
                        `UID:${item.id}-${index}-mycic`,
                        `DTSTAMP:${stamp}`,
                        `DTSTART;VALUE=DATE:${icsDate(start)}`,
                        `DTEND;VALUE=DATE:${icsDate(endExclusive)}`,
                        `SUMMARY:${icsText(`${label}: ${item.title || 'Pengumuman kampus'}`)}`,
                        `DESCRIPTION:${icsText(`${deadline.context || ''}\n${window.location.origin}/announcement/${item.id}`)}`,
                        'BEGIN:VALARM',
                        'TRIGGER:-P1D',
                        'ACTION:DISPLAY',
                        `DESCRIPTION:${icsText(item.title || 'Tenggat pengumuman kampus')}`,
                        'END:VALARM',
                        'END:VEVENT'
                    );
                });

                lines.push('END:VCALENDAR');

                return lines.map(icsFold).join('\r\n');
            };

            const downloadIcs = (item, deadlines) => {
                const upcoming = futureDeadlines(deadlines);

                if (!upcoming.length) {
                    app.showNotice('Tenggat di pengumuman ini sudah lewat semua.', 'info');

                    return;
                }

                const url = URL.createObjectURL(new Blob([buildIcs(item, upcoming)], { type: 'text/calendar;charset=utf-8' }));
                const link = document.createElement('a');

                link.href = url;
                link.download = `tenggat-pengumuman-${item.id.slice(0, 8)}.ics`;
                document.body.appendChild(link);
                link.click();
                link.remove();
                window.setTimeout(() => URL.revokeObjectURL(url), 1000);
                app.showNotice('Berkas kalender tersimpan. Buka berkasnya untuk menambahkan tenggat ke kalendermu.', 'success');
            };

            const renderSticky = (item, links, deadlines) => {
                const form = links.find((link) => link.kind === 'form' && !link.is_campus_file);
                const file = links.find((link) => link.is_campus_file);
                const email = (Array.isArray(item.emails) ? item.emails : [])[0];
                let action = '';

                if (form) {
                    action = `<a href="${app.escapeHtml(form.href)}" target="_blank" rel="noopener noreferrer" class="${app.buttonClass('primary')} w-full">${ICONS.form}Buka formulir</a>`;
                } else if (file) {
                    action = `<a href="${app.escapeHtml(file.href)}" target="_blank" rel="noopener noreferrer" class="${app.buttonClass('primary')} w-full" data-online-required>${ICONS.file}Unduh berkas</a>`;
                } else if (email && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(email))) {
                    action = `<a href="mailto:${app.escapeHtml(email)}" class="${app.buttonClass('primary')} w-full">${ICONS.mail}Kirim email</a>`;
                } else if (futureDeadlines(deadlines).length) {
                    action = `<button type="button" class="${app.buttonClass('primary')} w-full" data-add-calendar>${ICONS.calendar}Tambah tenggat ke kalender</button>`;
                }

                stickyHost.innerHTML = action ? `<div class="${app.ui.stickyAction} lg:hidden" data-sticky-action>${action}</div>` : '';
            };

            const renderDetail = (payload) => {
                const item = payload?.data;

                if (!item || typeof item !== 'object' || !item.id) {
                    return;
                }

                const links = linksOf(item);
                const deadlines = deadlinesOf(item);

                rendered = true;
                host.removeAttribute('aria-busy');
                heroHost.innerHTML = renderHeader(item, deadlines);
                host.innerHTML = `
                    <div class="${app.ui.splitXl} max-w-3xl xl:max-w-none">
                        <article class="${app.ui.splitMain}" aria-labelledby="announcement-title">
                            ${renderSteps(item)}
                            <section class="${app.ui.card}" data-slot="card" aria-labelledby="announcement-body-title">
                                <h2 class="sr-only" id="announcement-body-title">Isi pengumuman</h2>
                                <div class="${app.ui.cardBody} text-sm leading-relaxed text-ink wrap-break-word sm:text-[15px] sm:leading-7 [&_a]:font-medium [&_a]:text-brand-text [&_a]:underline [&_a]:underline-offset-4 [&_a]:wrap-anywhere [&_b]:font-semibold [&_li]:mb-1 [&_ol]:mb-3 [&_ol]:list-decimal [&_ol]:ps-5 [&_p]:mb-3 [&_p:last-child]:mb-0 [&_strong]:font-semibold [&_table]:w-full [&_table]:border-collapse [&_td]:border-b [&_td]:border-border-light [&_td]:px-3 [&_td]:py-2.5 [&_td]:align-top [&_th]:border-b [&_th]:border-border [&_th]:bg-surface/40 [&_th]:px-3 [&_th]:py-2 [&_th]:text-start [&_th]:text-xs [&_th]:font-medium [&_th]:text-ink-muted [&_tr:last-child>td]:border-b-0 [&_ul]:mb-3 [&_ul]:list-disc [&_ul]:ps-5" data-announcement-body></div>
                            </section>
                        </article>
                        <aside class="${app.ui.splitAsideXl}" aria-label="Tindakan dan tenggat">
                            ${renderActions(item, links, deadlines)}
                            ${renderDeadlineCard(deadlines)}
                        </aside>
                    </div>
                `;
                host.querySelector('[data-announcement-body]').replaceChildren(buildBody(item, links));
                bindSteps(item);
                renderSticky(item, links, deadlines);
                document.title = `${item.title || 'Pengumuman'} · Pengumuman`;
                showCanonicalUrl(item);
                markRead(item);
                app.refreshOnlineRequiredState();
                current = { item, deadlines };
            };

            // An old link carries the wording-based id; the address bar gets the id that survives edits.
            const showCanonicalUrl = (item) => {
                if (item.id === announcementId || !/^[0-9a-f]{40}$/.test(String(item.id))) {
                    return;
                }

                try {
                    window.history.replaceState(window.history.state, '', `/announcement/${item.id}${window.location.search}${window.location.hash}`);
                } catch (error) {
                    // The old address still opens this announcement.
                }
            };

            const renderNotFound = (message) => {
                rendered = true;
                host.removeAttribute('aria-busy');
                stickyHost.innerHTML = '';
                renderHeroFallback();
                host.innerHTML = `
                    <div class="${app.ui.empty}" data-slot="empty" role="status">
                        <span class="${app.ui.emptyIcon}">${ICONS.fileOff}</span>
                        <p class="${app.ui.emptyTitle}">Pengumuman tidak ditemukan</p>
                        <p class="${app.ui.emptySubtitle}">${app.escapeHtml(String(message || '').replace(/^Pengumuman tidak ditemukan\.?\s*/i, '') || 'Bisa jadi sudah diperbarui atau dihapus di portal.')}</p>
                        <a href="/announcement" class="${app.buttonClass('primary', { small: true })} mt-2">Lihat semua pengumuman</a>
                    </div>
                `;
            };

            document.addEventListener('click', (event) => {
                if (!(event.target instanceof Element) || !event.target.closest('[data-add-calendar]')) {
                    return;
                }

                if (current) {
                    downloadIcs(current.item, current.deadlines);
                }
            });

            const primeFromFeed = () => {
                const cached = app.getCachedResponse('/api/announcement');
                const items = Array.isArray(cached?.payload?.data?.items) ? cached.payload.data.items : [];
                const item = items.find((entry) => entry?.id === announcementId) || items.find((entry) => entry?.legacy_id === announcementId);

                if (item) {
                    renderDetail({ data: item, meta: cached.payload.meta });
                }
            };

            try {
                if (!app.primeCachedView(detailUrl, renderDetail, 'detail pengumuman')) {
                    primeFromFeed();
                }
            } catch (error) {
                // A blocked device cache only means the page waits for the server.
            }

            const ready = await app.ensureAuthenticated();

            if (!ready) {
                return;
            }

            const loadAnnouncement = async ({ force = false, background = false } = {}) => {
                try {
                    const payload = await app.request(detailUrl, { fresh: force, background });

                    if (!payload) {
                        return;
                    }

                    renderDetail(payload);
                    app.markViewSynced('Pengumuman', payload, { force });
                } catch (error) {
                    if (error?.status === 404) {
                        // Not found on the pages MyCIC searched is not proof it is gone:
                        // a copy already on screen stays, marked as unverified.
                        if (rendered) {
                            app.setSyncStatus('Pengumuman ini tidak ditemukan lagi di portal. Yang tampil adalah salinan tersimpan.', 'warning');

                            return;
                        }

                        renderNotFound(app.describeError(error));
                        app.setSyncStatus('Pengumuman tidak ditemukan di portal.', 'warning');

                        return;
                    }

                    if (rendered) {
                        app.setSyncStatus(`Pengumuman belum bisa diperbarui. ${app.describeError(error)}`, 'warning');

                        return;
                    }

                    host.removeAttribute('aria-busy');
                    renderHeroFallback();
                    app.mountErrorState(host, 'Pengumuman gagal dimuat.', () => loadAnnouncement({ force }), {
                        subtitle: app.describeError(error),
                    });
                    app.setSyncStatus('Pengumuman gagal dimuat.', 'error');
                }
            };

            app.registerSyncHandler('announcement-show', loadAnnouncement, { aliases: ['announcement-index'] });

            await loadAnnouncement();
        });
    </script>
@endpush
