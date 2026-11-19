{{--
    A small calendar square for a list row (app.ui.dateTile): the month
    above the day, gray. `month` and `day` are strings ("Nov", "20").
    `accent` is ignored; delete it where you see it.
--}}
@props(['month' => null, 'day' => null, 'accent' => null])

<span {{ $attributes->class('flex size-11 shrink-0 flex-col items-center justify-center rounded-lg bg-surface text-ink') }} data-slot="date-tile">
    <span class="text-[10px] font-medium uppercase leading-none tracking-wide opacity-80">{{ $month }}</span>
    <span class="mt-0.5 text-base font-semibold leading-none tabular-nums">{{ $day }}</span>
</span>
