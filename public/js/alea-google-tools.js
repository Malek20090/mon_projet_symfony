(function () {
    function categoryFromCurrentEvent() {
        var t = ((window.currentEvent && window.currentEvent.title) || '').toLowerCase();
        if (/voiture|moteur|essuie|batterie|pneu|auto/.test(t)) return 'VOITURE';
        if (/urgence|medicament|consultation|analyse|sante/.test(t)) return 'SANTE';
        if (/telephone|tel|mobile|ordinateur|pc|laptop|ecran|tv|console|electro/.test(t)) return 'ELECTRONIQUE';
        if (/machine|frigo|telephone|chaudiere|panne/.test(t)) return 'PANNE_MAISON';
        if (/scolarite|ecole|universite|frais scolaire/.test(t)) return 'EDUCATION';
        return 'AUTRE';
    }

    function setStatus(msg) {
        var status = document.getElementById('googleToolsStatus');
        if (status) status.textContent = msg || '';
    }

    function renderNearby(places) {
        var container = document.getElementById('nearbyResults');
        if (!container) return;

        if (!places || !places.length) {
            container.innerHTML = '<div class="alert alert-warning py-2 mb-0">No nearby places found.</div>';
            return;
        }

        container.innerHTML = places.map(function (x) {
            var mapUrl = 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(x.lat + ',' + x.lng);
            var type = (x.types && x.types.length) ? x.types[0] : 'service';
            return '<div class="nearby-card"><div class="d-flex justify-content-between align-items-start"><div><div class="nearby-name"><i class="fas fa-location-dot text-primary me-2"></i>' + (x.name || '-') + '</div><div class="small text-muted mt-1">' + (x.address || '-') + '</div><span class="nearby-type">' + type + '</span></div></div><a class="small mt-2 d-inline-block" target="_blank" href="' + mapUrl + '"><i class="fas fa-arrow-up-right-from-square me-1"></i>Open in Maps</a></div>';
        }).join('');
    }

    window.createGoogleCalendarReminder = function () {
        if (!window.currentEvent) {
            alert('Select a case first.');
            return;
        }

        var title = window.currentEvent.title || 'Rappel';
        var category = categoryFromCurrentEvent();
        var url = '/alea/google/calendar-link?title=' + encodeURIComponent('Rappel mensuel: ' + title) + '&category=' + encodeURIComponent(category) + '&days=30';
        setStatus('Creating calendar link...');
        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.success && d.url) {
                    window.open(d.url, '_blank');
                    setStatus('Calendar link generated. Searching best nearby places...');
                    window.findNearbyServices();
                    return;
                }
                setStatus('Calendar failed.');
                alert(d.message || 'Unable to generate calendar link.');
            })
            .catch(function () {
                setStatus('Calendar error.');
                alert('Calendar error.');
            });
    };

    window.findNearbyServices = function () {
        if (!navigator.geolocation) {
            alert('Geolocation is not supported.');
            return;
        }

        setStatus('Locating...');
        navigator.geolocation.getCurrentPosition(function (p) {
            var url = '/alea/google/nearby?lat=' + encodeURIComponent(p.coords.latitude) + '&lng=' + encodeURIComponent(p.coords.longitude) + '&category=' + encodeURIComponent(categoryFromCurrentEvent());
            setStatus('Searching nearby services...');

            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) {
                    if (!r.ok) {
                        throw new Error('HTTP ' + r.status);
                    }
                    return r.json();
                })
                .then(function (d) {
                    if (!d.success) {
                        setStatus('Nearby failed.');
                        alert(d.message || 'Nearby search failed.');
                        return;
                    }

                    renderNearby(d.places || []);
                    setStatus('Nearby loaded via: ' + (d.source || 'unknown'));
                })
                .catch(function (e) {
                    setStatus('Nearby error.');
                    alert('Nearby search error: ' + e.message);
                });
        }, function () {
            setStatus('Location denied.');
            alert('Please allow location access.');
        });
    };
})();
