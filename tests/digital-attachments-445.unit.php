<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../storage/plugins/digital-library/DigitalLibraryPlugin.php';
if (!function_exists('__')) { function __(string $s, mixed ...$args): string { return $args ? sprintf($s, ...$args) : $s; } }
if (!function_exists('url')) { function url(string $s): string { return $s; } }
use App\Support\DigitalAttachments as Attachments;
$checks = 0;
function check(bool $ok, string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; echo "OK $message\n"; }
$rows = [
 ['url'=>'/uploads/digital/book.pdf','label'=>'PDF edition','kind'=>'ebook'],
 ['url'=>'https://example.org/book.epub','label'=>'ePub edition','kind'=>'ebook'],
 ['url'=>'https://example.org/review.pdf','label'=>'Review <&>','kind'=>'supplement'],
 ['url'=>'/uploads/digital/part-one.mp3','label'=>'Part one','kind'=>'audio'],
 ['url'=>'/uploads/digital/part-two.m4a','label'=>'Part two','kind'=>'audio'],
];
check(Attachments::normalize($rows)===$rows,'PDF, ePub, supplementary document and two audio tracks retain order and labels');
check(count(Attachments::normalize([...$rows,$rows[0]]))===5,'duplicate URLs of the same kind are deduplicated');
check(Attachments::normalize([['url'=>' ']])===[],'blank URLs remove rows');
check(Attachments::normalize([['url'=>'/uploads/digital/book.pdf']])[0]['label']==='book.pdf','a filename supplies the default title');
foreach (['javascript:alert(1)','data:text/html,test','//evil.example/x','/uploads/../secret','/uploads/%2e%2e/secret','/uploads/%5csecret','https://example.org/a b','file:///tmp/book.pdf'] as $bad) {
 try { Attachments::normalize([['url'=>$bad]]); check(false,'unsafe URL rejected'); }
 catch (InvalidArgumentException $e) { check(true,'unsafe URL rejected: '.$bad); }
}
foreach ([null,'[]',[['url'=>[]]],[['url'=>'https://example.org/a','kind'=>'unknown']],[['url'=>'https://example.org/a','label'=>str_repeat('x',256)]],array_fill(0,101,$rows[0])] as $bad) {
 try { Attachments::normalize($bad); check(false,'malformed collection rejected'); }
 catch (InvalidArgumentException $e) { check(true,'malformed collection rejected'); }
}
$legacy=['file_url'=>'/uploads/digital/old.pdf','audio_url'=>'/uploads/digital/old.mp3'];
check(count(Attachments::fromBook($legacy))===2,'legacy single-file records are readable without rewriting data');
check(Attachments::fromBook(['file_url'=>'javascript:alert(1)'])===[],'unsafe legacy URLs are not displayed');
$saved=Attachments::applySubmission($legacy,['digital_attachments_present'=>'1','digital_attachments'=>$rows]);
check(Attachments::fromBook($saved)===$rows,'the saved JSON round trips every attachment');
check($saved['file_url']===$rows[0]['url'] && $saved['audio_url']===$rows[3]['url'],'older clients keep the first edition and audio track');
$cleared=Attachments::applySubmission($saved,['digital_attachments_present'=>'1']);
check(Attachments::fromBook($cleared)===[] && $cleared['file_url']==='' && $cleared['audio_url']==='','removing all rows cannot resurrect old single-file links');
check(Attachments::applySubmission($saved,[])===$saved,'forms and imports omitting the editor preserve existing attachments');
$plugin=new DigitalLibraryPlugin();
ob_start();$plugin->renderFrontendButtons($saved);$html=(string)ob_get_clean();
check(substr_count($html,'<audio controls')===2,'both audio tracks have independent native players');
check(substr_count($html,'<iframe')===2,'the edition and PDF review can both be read inline');
check(str_contains($html,'book.epub'),'the ePub edition is downloadable alongside the PDF');
check(str_contains($html,'Review &lt;&amp;&gt;') && !str_contains($html,'Review <&>'),'labels are escaped in visible text and attributes');
ob_start();$plugin->renderAudioPlayer($saved);$plugin->renderPdfViewer($saved);$extra=(string)ob_get_clean();
check($extra==='','multiple attachments do not produce duplicate legacy viewers');
check(in_array(['table'=>'libri','column'=>'digital_attachments'],$plugin->expectedColumns(),true),'the upgrade self-heals the new collection column');
echo "SUCCESS $checks checks\n";
