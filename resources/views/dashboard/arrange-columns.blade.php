{{--
    Moves the widgets so the DOM order matches the screen. Below lg they
    keep the mobile order (actions, today, assignments, metrics,
    announcements). From lg (64rem) the KPI strip (metrics) moves to the
    top, right under the greeting header. From xl (80rem) the strip spans the
    12-column grid and the rest becomes a bento: today and assignments in
    the main column, actions and announcements in the aside. Not from lg:
    the sidebar takes 15rem there, which leaves an aside too narrow. Runs
    as soon as it is parsed.
--}}
<script>
    (() => {
        const layout = document.querySelector('[data-dashboard-layout]');

        if (!layout) {
            return;
        }

        const columns = {
            main: layout.querySelector('[data-dashboard-column="main"]'),
            aside: layout.querySelector('[data-dashboard-column="aside"]'),
        };
        const widget = (key) => layout.querySelector(`[data-dashboard-widget="${key}"]`);
        const large = window.matchMedia('(min-width: 64rem)');
        const wide = window.matchMedia('(min-width: 80rem)');
        const arrange = () => {
            if (wide.matches) {
                layout.prepend(widget('metrics'));
                ['today', 'assignments'].forEach((key) => columns.main.appendChild(widget(key)));
                ['actions', 'announcements'].forEach((key) => columns.aside.appendChild(widget(key)));

                return;
            }

            const order = large.matches
                ? ['metrics', 'actions', 'today', 'assignments', 'announcements']
                : ['actions', 'today', 'assignments', 'metrics', 'announcements'];

            order.forEach((key) => layout.appendChild(widget(key)));
        };

        arrange();
        large.addEventListener('change', arrange);
        wide.addEventListener('change', arrange);
    })();
</script>
