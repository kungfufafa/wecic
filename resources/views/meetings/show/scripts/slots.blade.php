{{--
    Pertemuan script, the action column's slots, rebuilt only when their
    markup changes so a re-render keeps a form the student is using. Part of
    the DOMContentLoaded handler opened in meetings/show.blade.php: it
    shares that scope (app, meetingId, coursesId, endpoint and the constants
    in setup).
--}}
            /**
             * The action column is kept in slots that are only rebuilt when their
             * markup changes: a re-render (cached copy, then the network copy, then a
             * realtime re-sync) must not replace a form the student is using.
             */
            const renderedSlots = new Map();

            const ensureActionSlots = () => {
                const actionsContainer = document.getElementById('meeting-actions');

                if (!actionsContainer.querySelector('[data-meeting-slot]')) {
                    actionsContainer.innerHTML = `
                        <div class="space-y-5">
                            <div data-meeting-slot="attendance"></div>
                            <div data-meeting-slot="assignment"></div>
                            <div data-meeting-slot="notebook"></div>
                        </div>
                    `;
                    renderedSlots.clear();
                }

                return actionsContainer;
            };

            /**
             * `state` holds what the slot's handlers send (payload ids), so a change
             * there rebinds them even when the markup looks the same.
             */
            const renderSlot = (name, markup, state, bind) => {
                const slot = ensureActionSlots().querySelector(`[data-meeting-slot="${name}"]`);
                const signature = `${markup}\u0000${JSON.stringify(state)}`;

                if (renderedSlots.get(name) === signature) {
                    return;
                }

                slot.innerHTML = markup;
                slot.hidden = markup.trim() === '';
                renderedSlots.set(name, signature);
                bind?.(slot);
            };

