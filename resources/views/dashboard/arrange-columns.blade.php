{{--
    From lg up the widgets move into the 8/4 columns (today, assignments and
    announcements in the main one, actions and metrics in the aside); below
    lg they go back to the mobile order. Runs as soon as it is parsed.
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
        const wide = window.matchMedia('(min-width: 64rem)');
        const arrange = () => {
            if (wide.matches) {
                ['today', 'assignments', 'announcements'].forEach((key) => columns.main.appendChild(widget(key)));
                ['actions', 'metrics'].forEach((key) => columns.aside.appendChild(widget(key)));

                return;
            }

            ['actions', 'today', 'assignments', 'metrics', 'announcements'].forEach((key) => layout.appendChild(widget(key)));
        };

        arrange();
        wide.addEventListener('change', arrange);
    })();
</script>
