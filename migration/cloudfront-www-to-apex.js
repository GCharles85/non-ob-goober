// CloudFront Function (runtime: cloudfront-js-2.0) — viewer request.
// Canonicalizes the host: 301-redirects www.gooberboks.com -> apex gooberboks.com, preserving the
// path and query string. Pairs with the self-referential <link rel="canonical"> the SPA emits on the
// apex origin (CANONICAL_ORIGIN in gooberbox-web/src/App.tsx) to clear Search Console's
// "duplicate without user-selected canonical" / "page with redirect" on the www variant.
//
// Attach: CloudFront console -> distribution EX1RJIT2FI0QQ -> Behaviors -> Default (*) -> Edit ->
// Function associations -> Viewer request -> this function. (The default behavior is enough: the
// redirect fires on the first document request, so the viewer lands on apex before the SPA loads
// and never issues /api calls from www.)
function handler(event) {
    var request = event.request;
    var headers = request.headers;
    var host = headers.host && headers.host.value;

    if (host === 'www.gooberboks.com') {
        // Rebuild the query string from the parsed object, re-encoding so values like the login
        // ?next=<encoded path> survive the round trip.
        var qs = '';
        var parts = [];
        for (var name in request.querystring) {
            var q = request.querystring[name];
            if (q.multiValue) {
                for (var i = 0; i < q.multiValue.length; i++) {
                    parts.push(encodeURIComponent(name) + '=' + encodeURIComponent(q.multiValue[i].value));
                }
            } else {
                parts.push(encodeURIComponent(name) + '=' + encodeURIComponent(q.value));
            }
        }
        if (parts.length) qs = '?' + parts.join('&');

        return {
            statusCode: 301,
            statusDescription: 'Moved Permanently',
            headers: {
                location: { value: 'https://gooberboks.com' + request.uri + qs }
            }
        };
    }

    return request;
}
