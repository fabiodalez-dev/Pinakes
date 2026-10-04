<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
use App\Support\CitationStyles as C;
$count=0;
function same(string $actual,string $expected,string $label):void { global $count;if($actual!==$expected)throw new RuntimeException("$label\nExpected: $expected\nActual: $actual");$count++;echo "OK $label\n"; }
$book=['type'=>'book','authors'=>['Eco, Umberto'],'title'=>'Il nome della rosa','edition'=>'2','publisher'=>'Bompiani','year'=>'1980'];
same(C::oxford($book)['text'],'Eco, U. Il nome della rosa. 2nd ed. (Bompiani, 1980).','book: initials, title, edition, publisher and year');
same(C::oxford($book)['html'],'Eco, U. <i>Il nome della rosa</i>. 2nd ed. (Bompiani, 1980).','the book title alone is italic');
$article=['type'=>'article','authors'=>['Petersen, Hans Uwe'],'title'=>'På sporet','container'=>'Arbejderhistorie','year'=>'1988','volume'=>'12','issue'=>'31','pageStart'=>'18','pageEnd'=>'38','doi'=>'10.1234/example'];
same(C::oxford($article)['text'],'Petersen, H.U. På sporet. Arbejderhistorie. 12: 31 (1988): pp. 18–38. https://doi.org/10.1234/example','journal: enumeration, pages and DOI');
same(C::oxford(['isNewspaper'=>true,'day'=>28,'month'=>9,'year'=>'2026','container'=>'Newspaper','title'=>'Article','pageStart'=>'3'])['text'],'Article. Newspaper. (28/9 2026), p. 3.','newspaper: the actual day, month and year');
same(C::oxford(['type'=>'chapter','authors'=>['Petersen, Hans Uwe'],'title'=>'Die Emigration','container'=>'Exil in Dänemark','editors'=>['Müller, Anna','Jensen, Peter'],'publisher'=>'Museum Tusculanum','year'=>'1991','pageStart'=>'45','pageEnd'=>'67'])['text'],'Petersen, H.U. Die Emigration. In Müller, A. & Jensen, P. (eds.). Exil in Dänemark. (Museum Tusculanum, 1991), pp. 45–67.','chapter: editors, host, imprint and pages');
same(C::oxford(['type'=>'book','editors'=>['Byström, Mikael','Frohnert, Pär'],'title'=>'Reaching a state of hope','year'=>'2013','publisher'=>'Nordic Academic Press'])['text'],'Byström, M. & Frohnert, P. (eds.). Reaching a state of hope. (Nordic Academic Press, 2013).','edited book: LIBRIS use case');
same(C::oxford(['type'=>'book','title'=>'Unknown author?'])['text'],'Unknown author? (n.d.).','missing author, publisher and date do not invent information or double punctuation');
same(C::oxford(['type'=>'chapter','title'=>'Chapter','pageStart'=>'4','pageEnd'=>'9'])['text'],'Chapter. (n.d.), pp. 4–9.','a chapter without its host still keeps the pages');
same(C::oxford(['type'=>'book','authors'=>['Library <&>'],'title'=>'<script>'])['html'],'Library &lt;&amp;&gt;. <i>&lt;script&gt;</i>. (n.d.).','HTML escapes all metadata');
same((string)count(C::all($book)),'5','books and articles offer all five requested styles');
echo "SUCCESS $count checks\n";
