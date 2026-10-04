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

// Legacy columns were free text: a value the list would reject must neither vanish from the
// editor nor be blanked by the next save of an unrelated field.
$repaired=['uploads/digital/old.pdf'=>'/uploads/digital/old.pdf','/uploads/digital/a b.pdf'=>'/uploads/digital/a%20b.pdf','https://example.org/città.pdf'=>'https://example.org/citt%C3%A0.pdf'];
foreach ($repaired as $stored=>$expected) {
 $book=['file_url'=>$stored,'audio_url'=>null];
 $shown=Attachments::fromBook($book,true);
 check(count($shown)===1 && $shown[0]['url']===$expected && empty($shown[0]['invalid']),'a legacy link the list would reject is repaired, not hidden: '.$stored);
 $resaved=Attachments::applySubmission($book,['digital_attachments_present'=>'1','digital_attachments'=>$shown]);
 check($resaved['file_url']===$expected,'saving the form unchanged keeps the repaired legacy link: '.$stored);
}
check(Attachments::fromBook(['file_url'=>'javascript:alert(1)'],true)===[['url'=>'javascript:alert(1)','label'=>'','kind'=>'ebook','invalid'=>true]],'the editor still shows an unreadable legacy link, marked invalid');
try { Attachments::applySubmission(['file_url'=>'javascript:alert(1)'],['digital_attachments_present'=>'1','digital_attachments'=>[['url'=>'javascript:alert(1)','label'=>'','kind'=>'ebook']]]); check(false,'an unreadable legacy link is not saved silently'); }
catch (InvalidArgumentException $e) { check(true,'an unreadable legacy link refuses the save instead of being blanked'); }
$book=['file_url'=>'javascript:alert(1)'];
ob_start();include __DIR__.'/../storage/plugins/digital-library/views/admin-form-fields.php';$form=(string)ob_get_clean();
check(substr_count($form,'digital-attachment-invalid')===1 && str_contains($form,'value="javascript:alert(1)"'),'the editor explains which saved link must be fixed');
// The single-file columns are VARCHAR(255): an edition or track longer than that could not be mirrored.
try { Attachments::normalize([['url'=>'https://example.org/'.str_repeat('a',240).'.pdf','kind'=>'ebook']]); check(false,'over-long edition URL rejected'); }
catch (InvalidArgumentException $e) { check(true,'an edition URL longer than the legacy column is rejected'); }
try { Attachments::normalize([['url'=>'https://example.org/'.str_repeat('a',240).'.mp3','kind'=>'audio']]); check(false,'over-long audio URL rejected'); }
catch (InvalidArgumentException $e) { check(true,'an audio URL longer than the legacy column is rejected'); }
check(count(Attachments::normalize([['url'=>'https://example.org/'.str_repeat('a',600),'kind'=>'supplement']]))===1,'a related document may keep a long URL, since it is never mirrored');
// A column changed outside the editor (import, API, plugin disabled) wins for its slot.
$external=['file_url'=>'/uploads/digital/replaced.pdf']+$saved;
$read=Attachments::fromBook($external);
check($read[0]['url']==='/uploads/digital/replaced.pdf' && $read[1]['url']===$rows[1]['url'] && count($read)===5,'an edition replaced outside the editor is shown instead of the stale one, other rows kept');
check(Attachments::fromBook(['audio_url'=>'']+$saved)[3]['url']===$rows[4]['url'] && count(Attachments::fromBook(['audio_url'=>'']+$saved))===4,'an audio track cleared outside the editor is not resurrected');
check(Attachments::fromBook(['file_url'=>'/uploads/digital/old.pdf','digital_attachments'=>null])===[['url'=>'/uploads/digital/old.pdf','label'=>'old.pdf','kind'=>'ebook']],'a record without the list still reads its column');
// Badges and inline reading.
$supplementOnly=Attachments::applySubmission(['file_url'=>'','audio_url'=>''],['digital_attachments_present'=>'1','digital_attachments'=>[$rows[2]]]);
ob_start();$book=$supplementOnly;include __DIR__.'/../storage/plugins/digital-library/views/badge-icons.php';$badge=(string)ob_get_clean();
check(!str_contains($badge,'ebook-icon'),'a review alone does not advertise an eBook');
ob_start();$book=$saved;include __DIR__.'/../storage/plugins/digital-library/views/badge-icons.php';$badge=(string)ob_get_clean();
check(str_contains($badge,'ebook-icon') && str_contains($badge,'audio-icon'),'an edition and a track still show both badges');
check(!str_contains($html,'sandbox'),'inline PDFs are not sandboxed, which would stop the browser PDF viewer');
// A root-relative path outside /uploads/ was stored and shown by the old single-file
// field; after the upgrade it must still be shown and savable, not hidden or blocking.
$legacyPath=Attachments::fromBook(['file_url'=>'/files/manual.pdf','digital_attachments'=>null]);
check($legacyPath===[['url'=>'/files/manual.pdf','label'=>'manual.pdf','kind'=>'ebook']],'a legacy link to another path of this site is shown to readers');
check(empty(Attachments::fromBook(['file_url'=>'/files/manual.pdf'],true)[0]['invalid']),'the editor does not mark it invalid, so the record can be saved');
foreach (['/files/../secret','/files/%2e%2e/secret','/files/%5csecret','//evil.example/files/x'] as $bad) {
    try { Attachments::normalize([['url'=>$bad]]); check(false,'unsafe local path rejected: '.$bad); }
    catch (InvalidArgumentException $e) { check(true,'unsafe local path rejected: '.$bad); }
}
echo "SUCCESS $checks checks\n";
