<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/storage/plugins/emeroteca/src/Support/ArticleMarcXml.php';
use App\Plugins\Emeroteca\Support\ArticleMarcXml;
$count = 0;
function verify(bool $ok, string $label): void {
    global $count;
    if (!$ok) { throw new RuntimeException($label); }
    $count++; echo "OK $label\n";
}
$row = ['id'=>12,'reference_key'=>'probe','titolo'=>'På sporet & <test>','sottotitolo'=>'Solidaritet',
    'autori'=>'Petersen, Hans Uwe; Sørensen, Åse','contenitore_titolo'=>'Arbejderhistorie',
    'issn'=>'0107-8461','volume'=>'12','numero'=>'31','anno_pubblicazione'=>1988,'pagine'=>'18–38',
    'note_private'=>'PRIVATE','pdf_path'=>'SECRET.pdf','risorsa_url'=>'PRIVATE-URL','risorsa_pubblica'=>0,
    'lingua'=>'deu','paese'=>'dk','classificazione_schema'=>'DK5','classificazione'=>'33.129',
    'nota_possesso'=>'Copy / offprint only','collocazione'=>'BOX-7','created_at'=>'2026-09-01 10:00:00'];
$parse = static function(string $xml): DOMXPath {
    $doc = new DOMDocument();
    if (!$doc->loadXML($xml, LIBXML_NONET)) { throw new RuntimeException('Invalid XML'); }
    $xp = new DOMXPath($doc); $xp->registerNamespace('m','http://www.loc.gov/MARC21/slim'); return $xp;
};
$xml = ArticleMarcXml::format($row,'https://example.org/article/12','HOST-4');
$xp = $parse($xml);
verify(strlen($xp->evaluate('string(//m:controlfield[@tag="008"])'))===40, 'fixed fields have forty positions');
$value = static fn(string $tag,string $code): string => $xp->evaluate("string(//m:datafield[@tag='$tag']/m:subfield[@code='$code'])");
$leader = $xp->evaluate('string(//m:leader)');
verify(strlen($leader)===24 && $leader[6]==='a' && $leader[7]==='a', 'single article is monographic component at Leader/07');
verify($value('100','a')==='Petersen, Hans Uwe' && $value('700','a')==='Sørensen, Åse', 'separate authors retain Unicode and inverted names');
verify($value('245','a')===$row['titolo'] && $value('245','b')==='Solidaritet', 'title and subtitle round trip escaped XML');
verify($value('300','a')==='18–38', 'extent retains exact pagination');
verify($value('773','t')==='Arbejderhistorie' && $value('773','x')==='0107-8461', 'host title and ISSN');
verify($value('773','g')==='Vol. 12, No. 31, 1988, pp. 18–38', 'host enumeration chronology and pages');
verify($value('773','w')==='(Pinakes)HOST-4', 'a real host control number is carried in 773 $w with its agency prefix');
verify($xp->evaluate('string(//m:controlfield[@tag="003"])')==='Pinakes', '003 names the agency of the control numbers');
verify(!str_contains($xml,'PRIVATE') && !str_contains($xml,'SECRET'), 'public export excludes private metadata');
$fixed = $xp->evaluate('string(//m:controlfield[@tag="008"])');
// 008
verify(substr($fixed,0,6)==='260901', '008/00-05 is the date entered on file from created_at');
verify(substr($fixed,6,9)==='s1988    ', '008/06-14 carries the single known year');
verify(substr($fixed,15,3)==='dk ', '008/15-17 carries the MARC country code, blank-filled');
// The two code lists differ: an ISO code is mapped, never copied.
$countryOf = static fn(string $iso): string => substr($parse(ArticleMarcXml::format(['titolo'=>'C','paese'=>$iso]))->evaluate('string(//m:controlfield[@tag="008"])'),15,3);
verify($countryOf('DE')==='gw ' && $countryOf('GB')==='xxk' && $countryOf('SE')==='sw ', '008/15-17 uses MARC codes (DE gw, GB xxk, SE sw), not ISO ones');
verify($countryOf('ZZ')==='|||' && $countryOf('')==='|||', 'an unknown or missing country stays unspecified');
// 245 $c reads as on the item; the inverted form belongs to 100/700.
verify($value('245','c')==='Hans Uwe Petersen, Åse Sørensen', '245 $c gives the authors in direct order');
// The uploaded PDF is the electronic article when both it and the article are public (856 ind2 0).
$pdfOf = static fn(array $extra): DOMXPath => $parse(ArticleMarcXml::format($extra + $row, 'https://example.org/emeroteca/articolo/12'));
$pdf856 = '//m:datafield[@tag="856"][@ind2="0"]/m:subfield[@code="u"]';
verify($pdfOf(['pdf_pubblico'=>1])->evaluate("string($pdf856)")==='https://example.org/emeroteca/articolo/12/pdf', 'a public PDF is exported in 856 $u with its public address');
verify($pdfOf(['pdf_pubblico'=>1])->evaluate('string(//m:datafield[@tag="856"][@ind2="0"]/m:subfield[@code="y"])')==='Full text (PDF)', 'with link text in 856 $y');
verify($pdfOf(['pdf_pubblico'=>0])->evaluate("count($pdf856)")===0.0, 'a private PDF is never exported');
verify($parse(ArticleMarcXml::format(['pdf_pubblico'=>1]+$row))->evaluate("count($pdf856)")===0.0, 'nor is one of an unpublished article (no public record address)');
// Linked to a catalogued masthead, with no free-text journal title: 773 comes from the masthead.
$linked = $parse(ArticleMarcXml::format(['titolo'=>'L','contenitore_titolo'=>'','issn'=>'','testata_titolo'=>'Arbejderhistorie','testata_issn'=>'0107-8461']));
verify($linked->evaluate("string(//m:datafield[@tag='773']/m:subfield[@code='t'])")==='Arbejderhistorie' && $linked->evaluate("string(//m:datafield[@tag='773']/m:subfield[@code='x'])")==='0107-8461', 'a linked masthead fills 773 $t and $x when the free-text fields are empty');
// RIS: JF as well as T2, as in danish union records.
$ris = \App\Plugins\Emeroteca\Support\CitationFormatter::ris($row);
verify(str_contains($ris, "TY  - JOUR\r\n") && str_contains($ris, "T2  - Arbejderhistorie\r\n") && str_contains($ris, "JF  - Arbejderhistorie\r\n"), 'RIS names the journal in both T2 and JF');
$today = ArticleMarcXml::format(['titolo'=>'No dates']);
$todayXp = $parse($today);
$todayFixed = $todayXp->evaluate('string(//m:controlfield[@tag="008"])');
verify(strlen($todayFixed)===40 && preg_match('/^\d{6}$/', substr($todayFixed,0,6))===1 && !str_contains(substr($todayFixed,0,6),'|'), '008/00-05 falls back to today, never fill characters');
verify(substr($todayFixed,6,9)==='nuuuuuuuu', 'unknown date is n + uuuuuuuu, not fill characters');
// Language: MARC codes, never the stored ISO form
verify(substr($fixed,35,3)==='ger' && $value('041','a')==='ger', 'ISO 639-2/T deu exports as MARC ger in 008 and 041');
$lang = static function(string $code) use ($parse): array {
    $x = $parse(ArticleMarcXml::format(['titolo'=>'L','lingua'=>$code]));
    return [substr($x->evaluate('string(//m:controlfield[@tag="008"])'),35,3), $x->evaluate('string(//m:datafield[@tag="041"]/m:subfield[@code="a"])'), (int)$x->evaluate('count(//m:datafield[@tag="041"])')];
};
verify($lang('da')===['dan','dan',1], 'ISO 639-1 da exports as dan');
verify($lang('fra')===['fre','fre',1], 'ISO 639-2/T fra exports as fre');
verify($lang('nld')===['dut','dut',1], 'ISO 639-2/T nld exports as dut');
verify($lang('dan')===['dan','dan',1], 'codes identical in /B pass through');
verify($lang('qq')===['|||','',0], 'unknown two-letter code emits no 041 and leaves 008/35-37 unspecified');
verify($lang('')===['|||','',0], 'no language, no 041');
// Country
verify($value('044','c')==='DK', 'country is exported as ISO 3166 in 044 $c');
// Classification per scheme
verify($value('084','a')==='33.129' && $value('084','2')==='dk5', 'DK5 stays in 084 with $2');
$class = static function(string $scheme, string $notation) use ($parse): DOMXPath {
    return $parse(ArticleMarcXml::format(['titolo'=>'C','classificazione_schema'=>$scheme,'classificazione'=>$notation]));
};
$c = $class('DDC','853.92');
verify($c->evaluate('string(//m:datafield[@tag="082"]/m:subfield[@code="a"])')==='853.92' && $c->evaluate('string(//m:datafield[@tag="082"]/@ind1)')==='0' && $c->evaluate('string(//m:datafield[@tag="082"]/@ind2)')==='4' && $c->evaluate('count(//m:datafield[@tag="084"])')===0.0, 'DDC exports as 082 0 4, not 084');
verify($class('ddc','853.92')->evaluate('count(//m:datafield[@tag="082"])')===1.0 && $class('DDC23','853.92')->evaluate('count(//m:datafield[@tag="082"])')===1.0, 'lower-case and edition-suffixed DDC map to 082');
verify($class('UDC','821.133.1')->evaluate('string(//m:datafield[@tag="080"]/m:subfield[@code="a"])')==='821.133.1', 'UDC exports as 080');
verify($class('LCC','PT2621')->evaluate('string(//m:datafield[@tag="050"]/m:subfield[@code="a"])')==='PT2621', 'LCC exports as 050');
verify($class('RVK','GM 1234')->evaluate('string(//m:datafield[@tag="084"]/m:subfield[@code="2"])')==='rvk', 'RVK stays in 084 with $2 rvk');
$schemeOnly = $class('DDC','');
verify($schemeOnly->evaluate('count(//m:datafield[@tag="082" or @tag="084" or @tag="080" or @tag="050"])')===0.0, 'scheme without notation emits no classification field');
// Holdings: note public, shelf mark admin-only
verify($value('852','z')==='Copy / offprint only' && !str_contains($xml,'BOX-7'), 'public export carries the holdings note but never the shelf mark');
$admin = $parse(ArticleMarcXml::format($row,'https://example.org/article/12','',true));
verify($admin->evaluate('string(//m:datafield[@tag="852"]/m:subfield[@code="c"])')==='BOX-7' && $admin->evaluate('string(//m:datafield[@tag="852"]/m:subfield[@code="z"])')==='Copy / offprint only', 'admin export carries shelf mark and holdings note');
// 856: catalogue page is a related resource
verify($xp->evaluate('string(//m:datafield[@tag="856"]/@ind1)')==='4' && $xp->evaluate('string(//m:datafield[@tag="856"]/@ind2)')==='2' && $value('856','y')==='Catalogue record', 'catalogue page is 856 4 2 with $y Catalogue record');
$res = static function(array $extra) use ($parse): DOMXPath { return $parse(ArticleMarcXml::format(['titolo'=>'R'] + $extra)); };
$https = $res(['risorsa_url'=>'https://arkiv.example/a.pdf','risorsa_testo'=>'PDF','risorsa_pubblica'=>1]);
verify($https->evaluate('string(//m:datafield[@tag="856" and @ind2="0"]/m:subfield[@code="u"])')==='https://arkiv.example/a.pdf', 'published https resource is 856 4 0');
verify($res(['risorsa_url'=>'\\\\archivio\\scansioni\\a.pdf','risorsa_pubblica'=>1])->evaluate('count(//m:datafield[@tag="856"])')===0.0, 'a UNC share is never exported');
verify($res(['risorsa_url'=>'file:///srv/archivio/a.pdf','risorsa_pubblica'=>1])->evaluate('count(//m:datafield[@tag="856"])')===0.0, 'a file: URI is never exported');
verify($res(['risorsa_url'=>'','risorsa_testo'=>'Orphan text','risorsa_pubblica'=>1])->evaluate('count(//m:datafield[@tag="856"])')===0.0, 'published resource without URL emits no 856');
verify($res(['risorsa_url'=>'https://arkiv.example/a.pdf','risorsa_pubblica'=>0])->evaluate('count(//m:datafield[@tag="856"])')===0.0, 'unpublished resource is not exported');
// Tag order
$tags = array_map(static fn(DOMNode $n): string => $n->nodeValue, iterator_to_array($admin->query('//m:datafield/@tag')));
$sorted = $tags; sort($sorted, SORT_STRING);
verify($tags===$sorted && array_search('100',$tags,true) < array_search('700',$tags,true), 'datafields are in ascending tag order');
// Required subfields
$noHost = $parse(ArticleMarcXml::format(['titolo'=>'W'],'','HOST-9'));
verify($noHost->evaluate('count(//m:datafield[@tag="773"])')===0.0, '773 is never emitted with $w alone');
$emptyCredit = $parse(ArticleMarcXml::format(['titolo'=>'E','author_credits'=>[['nome_credito'=>'','identifiers'=>['https://d-nb.info/gnd/1']]]]));
verify($emptyCredit->evaluate('count(//m:datafield[@tag="100" or @tag="700"])')===0.0, 'an author field without $a is not emitted');
$sparse = $parse(ArticleMarcXml::format(['titolo'=>'Only title']));
verify($sparse->evaluate('count(//m:datafield[@tag="773"])')===0.0, 'sparse article invents no host');
verify($sparse->evaluate('count(//m:datafield[@tag="024"])')===0.0, 'missing DOI emits no identifier field');
verify($sparse->evaluate('count(//m:datafield[@tag="044"])')===0.0 && $sparse->evaluate('count(//m:datafield[@tag="852"])')===0.0 && $sparse->evaluate('count(//m:datafield[@tag="041"])')===0.0, 'sparse article invents no country, language or holdings');
$standalone = $parse(ArticleMarcXml::format($row));
verify($standalone->evaluate('count(//m:datafield[@tag="773"]/m:subfield[@code="w"])')===0.0, 'standalone citation needs no owned masthead');
// A chapter in an anthology (#412): the host is a book, so 773 carries its
// imprint in $d and its ISBN in $z, never an ISSN in $x.
$chapter = $parse(ArticleMarcXml::format(['titolo'=>'Die Emigration','autori'=>'Petersen, Hans Uwe','contenitore_tipo'=>'antologia',
    'contenitore_titolo'=>'Exil in Dänemark','contenitore_curatori'=>'Müller, Anna','contenitore_editore'=>'Museum Tusculanum',
    'contenitore_luogo'=>'København','isbn'=>'9780306406157','issn'=>'0107-8461','anno_pubblicazione'=>1991,'pagine'=>'45-67']));
$chapterValue = static fn(string $code): string => $chapter->evaluate("string(//m:datafield[@tag='773']/m:subfield[@code='$code'])");
verify($chapterValue('t')==='Exil in Dänemark' && $chapterValue('d')==='København : Museum Tusculanum, 1991', 'a chapter host carries title and imprint in 773 $t $d');
verify($chapterValue('z')==='9780306406157' && $chapterValue('x')==='', 'and the volume ISBN in 773 $z instead of an ISSN');
// Book export: the catalogue page is a related resource too.
require_once dirname(__DIR__).'/storage/plugins/z39-server/classes/RecordFormatter.php';
require_once dirname(__DIR__).'/storage/plugins/z39-server/classes/MARCXMLFormatter.php';
$bookDoc = new DOMDocument();
$bookEl = (new \Z39Server\MARCXMLFormatter($bookDoc))->format(['id'=>1,'titolo'=>'Book','public_url'=>'https://example.org/book/1']);
$bookDoc->appendChild($bookEl);
$bookXp = new DOMXPath($bookDoc); $bookXp->registerNamespace('m','http://www.loc.gov/MARC21/slim');
$book856 = $bookXp->query('//*[local-name()="datafield"][@tag="856"][*[local-name()="subfield"][@code="u"]="https://example.org/book/1"]')->item(0);
verify($book856 instanceof DOMElement && $book856->getAttribute('ind1')==='4' && $book856->getAttribute('ind2')==='2', 'book catalogue page 856 uses ind2 2 as well');
echo "SUCCESS $count MARCXML checks\n";
