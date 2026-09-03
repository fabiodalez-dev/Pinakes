def catalogue_identifiers:
  $catalogue_identifiers[0];

def public_catalogue_urls:
  $public_catalogue_urls[0];

def public_example_identifiers:
  $public_example_identifiers[0];

def is_known_catalogue_identifier:
  . as $identifier
  | (catalogue_identifiers | index($identifier)) != null;

def is_known_public_example_identifier:
  . as $identifier
  | (public_example_identifiers | index($identifier)) != null;

def without_query_or_fragment:
  sub("[?#].*$"; "");

def is_registered_canonical_url:
  . as $uri
  | ($uri | without_query_or_fragment) as $normalized
  | ($normalized | test(
      "^http://localhost:8081/(?:(?:it|en|de|fr|da)/)?[a-z0-9]+(?:-[a-z0-9]+)*/[a-z0-9]+(?:-[a-z0-9]+)*/[0-9]+$";
      "i"
    ))
    and ((public_catalogue_urls | index($normalized)) != null);

def is_static_bibliographic_uri:
  test(
    "^http://localhost:8081/(?:(?:it|en|de|fr|da)/)?(?:" +
    "(?:catalog|catalogo|catalogue|katalog)(?:\\.php)?" +
    "|(?:bog-detalje|book-detail|buch-detail|fiche|scheda)\\.php" +
    "|(?:bog|book|buch|libro|livre)/[0-9]+(?:/[a-z0-9]+(?:-[a-z0-9]+)*)?" +
    "|(?:auteur|author|autor|autore|editeur|editore|forfatter|forlag|genere|genre|publisher|verlag)/[^/?#]+" +
    "|api/(?:catalog|catalogo|catalogue|katalog)" +
    "|api/bibframe/book/[0-9]+(?:/(?:work|instance))?" +
    "|id/instance/[0-9]+" +
    "|libri/[0-9]+\\.rda\\.json" +
    ")(?:[?#][^#]*)?$";
    "i"
  );

def is_bibliographic_uri:
  is_static_bibliographic_uri or is_registered_canonical_url;

def is_target_uri:
  test("^http://localhost:8081(?:[/?#]|$)"; "i");

def is_catalogue_ean_instance:
  type == "object"
  and has("method") and (.method | type == "string")
  and has("evidence") and (.evidence | type == "string")
  and has("uri") and (.uri | type == "string")
  and has("param") and (.param | type == "string")
  and has("attack") and (.attack | type == "string")
  and (.method | ascii_upcase) == "GET"
  and (.evidence | test("^[0-9]{13}$"))
  and (
    (
      (.evidence | is_known_public_example_identifier)
      and (.uri | is_target_uri)
    )
    or (
      (.evidence | is_known_catalogue_identifier)
      and (.uri | is_bibliographic_uri)
    )
  )
  and (.param == "")
  and (.attack == "");

def is_catalogue_ean_false_positive:
  ((.pluginid // "") | tostring) == "10062"
  and ((.instances // []) | type == "array")
  and ((.instances // []) | length > 0)
  and ((.instances // []) | all(.[]; is_catalogue_ean_instance));

# A scheme-only source ("https:" / "http:" / "*") inside the named directive.
# "https://host" never matches: the token is followed by "//", not by a
# delimiter. Used to prove active-content directives stay host-listed.
def csp_directive_has_wildcard_source($name):
  test($name + "[^;]*[\\s](\\*|https?:)([\\s;]|$)");

# OWASP ZAP 10055 (CSP: Wildcard Directive) fires on the DELIBERATE
# scheme-wide sources shipped since 0.7.77: covers, author photos and plugin
# logos may be stored as remote HTTPS URLs (img-src), the Digital Library
# plays externally hosted audio (media-src) and the native PDF viewer can
# frame a user-configured remote PDF (frame-src). Those are passive/framed
# resources; the alert is ignored ONLY when every instance is the
# Content-Security-Policy header of the target origin, the directives ZAP
# lists as overly broad are a subset of exactly those three, and the quoted
# policy itself proves active content stays strict (default-src 'self',
# object-src 'none', base-uri 'self', form-action 'self', and no
# scheme-only source in script-src / style-src). Any other wildcard —
# script-src https:, a new loose directive, another origin — still blocks.
def is_intended_csp_wildcard_instance:
  type == "object"
  and has("method") and (.method | type == "string")
  and has("param") and (.param | type == "string")
  and has("uri") and (.uri | type == "string")
  and has("evidence") and (.evidence | type == "string")
  and has("otherinfo") and (.otherinfo | type == "string")
  and (.method | ascii_upcase) == "GET"
  and (.param | ascii_downcase) == "content-security-policy"
  and (.uri | is_target_uri)
  and (.evidence | contains("default-src 'self'"))
  and (.evidence | contains("object-src 'none'"))
  and (.evidence | contains("base-uri 'self'"))
  and (.evidence | contains("form-action 'self'"))
  and (.evidence | csp_directive_has_wildcard_source("script-src") | not)
  and (.evidence | csp_directive_has_wildcard_source("style-src") | not)
  and (
    .otherinfo
    | split("\n")
    | last
    | split(", ")
    | length > 0
    and all(.[]; . == "img-src" or . == "frame-src" or . == "media-src")
  );

def is_intended_csp_wildcard_false_positive:
  ((.pluginid // "") | tostring) == "10055"
  and ((.instances // []) | type == "array")
  and ((.instances // []) | length > 0)
  and ((.instances // []) | all(.[]; is_intended_csp_wildcard_instance));

def has_valid_riskcode:
  has("riskcode")
  and (
    (
      (.riskcode | type) == "number"
      and (
        .riskcode == 0
        or .riskcode == 1
        or .riskcode == 2
        or .riskcode == 3
      )
    )
    or ((.riskcode | type) == "string" and (.riskcode | test("^[0-3]$")))
  );

def is_valid_zap_alert:
  type == "object" and has_valid_riskcode;

def is_valid_zap_report:
  type == "object"
  and has("site")
  and (.site | type == "array")
  and (.site | length > 0)
  and (
    .site
    | all(.[];
        type == "object"
        and has("alerts")
        and (.alerts | type == "array")
        and (.alerts | all(.[]; is_valid_zap_alert))
      )
  );

def has_valid_catalogue_context:
  ($catalogue_identifiers | type == "array")
  and ($catalogue_identifiers | length == 1)
  and (catalogue_identifiers | type == "array")
  and (catalogue_identifiers | all(.[]; type == "string" and test("^[0-9]{13}$")))
  and ($public_catalogue_urls | type == "array")
  and ($public_catalogue_urls | length == 1)
  and (public_catalogue_urls | type == "array")
  and (public_catalogue_urls | all(.[]; type == "string"))
  and ($public_example_identifiers | type == "array")
  and ($public_example_identifiers | length == 1)
  and (public_example_identifiers | type == "array")
  and (public_example_identifiers | all(.[]; type == "string" and test("^[0-9]{13}$")));

if type != "array" or length != 1 then
  error("expected exactly one OWASP ZAP JSON document")
elif (has_valid_catalogue_context | not) then
  error("invalid catalogue context for OWASP ZAP filtering")
elif (.[0] | is_valid_zap_report | not) then
  error("invalid or incomplete OWASP ZAP JSON report")
else
  .[0]
  | [
      .site[].alerts[]
      | select((.riskcode | tonumber) >= 2)
      | select(is_catalogue_ean_false_positive | not)
      | select(is_intended_csp_wildcard_false_positive | not)
    ]
end
