<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
$user=Auth::requireUser(); $id=(int)($_GET['id']??$_POST['id']??0); $pdo=Database::connection();
$q=$pdo->prepare('SELECT * FROM artworks WHERE id=:id AND user_id=:u LIMIT 1');$q->execute(['id'=>$id,'u'=>$user['id']]);$artwork=$q->fetch();if(!$artwork){http_response_code(404);exit('Artwork not found.');}
$q=$pdo->prepare('SELECT mockup_file FROM mockups WHERE user_id=:u AND artwork_file=:f AND mockup_file != "" ORDER BY id ASC');$q->execute(['u'=>$user['id'],'f'=>$artwork['root_file']]);$available=array_map(fn($r)=>basename((string)$r['mockup_file']),$q->fetchAll());
$q=$pdo->prepare('SELECT * FROM social_video_workflows WHERE artwork_id=:id LIMIT 1');$q->execute(['id'=>$id]);$workflow=$q->fetch()?:[];$saved=json_decode((string)($workflow['setup_edited_json']??''),true);$sequence=is_array($saved['mockup_sequence']??null)?$saved['mockup_sequence']:$available;$notice='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST')try{$sequence=array_values(array_filter(array_map('basename',explode(',',(string)($_POST['mockup_sequence']??'')))));if(count($sequence)<2)throw new RuntimeException('Select at least two mockups.');$setup=['mockup_sequence'=>$sequence];$now=date('c');$notice='Sequence order saved.';$q->execute(['id'=>$id]);$workflow=$q->fetch()?:[];}catch(Throwable $e){$error=$e->getMessage();}
$video=(string)($workflow['video_url']??'');
function sh(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Social Video - The Artwork Curator</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        :root {
            --font-serif: 'Cormorant Garamond', Georgia, serif;
            --font-sans: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            
            /* High-end Art Gallery Light Palette */
            --gal-bg: #FAF9F6;          /* Plaster white */
            --gal-surface: #FFFFFF;     /* Pure white */
            --gal-surface-soft: #F4F3EE;/* Warm linen */
            --gal-border: #E5E3DD;      /* Soft clay line */
            --gal-ink: #141412;         /* Deep charcoal */
            --gal-muted: #7A7872;       /* Warm dust */
            --gal-accent: #9A7B56;      /* Gallery gold/bronze */
            --gal-accent-light: #F6F3EE;
            --gal-accent-hover: #7E6342;
            --gal-danger: #A63C3C;      /* Red wax seal */
            --gal-shadow: 0 4px 30px rgba(20, 20, 18, 0.02);
            --gal-shadow-hover: 0 20px 48px rgba(20, 20, 18, 0.05);
            --gal-radius: 4px;
        }

        body {
            margin: 0;
            padding: 0;
            font-family: var(--font-sans);
            background: var(--gal-bg);
            color: var(--gal-ink);
            line-height: 1.6;
        }

        .app-shell {
            background: var(--gal-bg);
            grid-template-columns: 260px 1fr;
        }

        .sidebar {
            background: var(--gal-surface);
            color: var(--gal-ink);
            border-right: 1px solid var(--gal-border);
        }

        .sidebar-head {
            background: var(--gal-bg);
        }

        .workspace.sv {
            max-width: 1200px;
            margin: auto;
            padding: 40px;
        }

        .workspace h1 {
            font-family: var(--font-serif);
            font-size: 36px;
            font-weight: 500;
            margin: 0 0 10px;
            color: var(--gal-ink);
        }

        .workspace p {
            color: var(--gal-muted);
            font-size: 15px;
            margin: 0 0 30px;
        }

        /* Mockups ordering container */
        .mockups {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 20px;
            padding: 24px;
            background: var(--gal-surface-soft);
            border: 1px solid var(--gal-border);
            border-radius: var(--gal-radius);
            margin-bottom: 24px;
        }

        @media (max-width: 1000px) {
            .mockups {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        .mockup {
            background: var(--gal-surface);
            border: 1px solid var(--gal-border);
            border-radius: var(--gal-radius);
            padding: 16px;
            box-shadow: var(--gal-shadow);
            cursor: grab;
            transition: all 0.2s ease;
            display: flex;
            flex-direction: column;
        }

        .mockup:hover {
            transform: translateY(-2px);
            box-shadow: var(--gal-shadow-hover);
        }

        .mockup.drag {
            opacity: 0.35;
        }

        .mockup img {
            width: 100%;
            height: 240px;
            object-fit: cover;
            border-radius: 2px;
        }

        .mockup .number {
            font-size: 10px;
            color: var(--gal-muted);
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.08em;
            margin-bottom: 8px;
            text-align: left;
        }

        /* Cards and results */
        .contexts {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 24px;
            margin-top: 24px;
        }

        .card {
            background: var(--gal-surface);
            border: 1px solid var(--gal-border);
            padding: 24px;
            border-radius: var(--gal-radius);
            box-shadow: var(--gal-shadow);
            display: flex;
            flex-direction: column;
            position: relative;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .card:hover {
            transform: translateY(-2px);
            box-shadow: var(--gal-shadow-hover);
        }

        .card.generated {
            border-top: 3px solid var(--gal-accent);
        }

        .card .number {
            font-size: 10px;
            color: var(--gal-muted);
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.08em;
            margin-bottom: 4px;
        }

        .card h3 {
            font-family: var(--font-serif);
            font-size: 20px;
            margin: 0 0 4px;
            font-weight: 500;
        }

        .card .purpose {
            font-size: 11px;
            text-transform: uppercase;
            color: var(--gal-muted);
            letter-spacing: 0.05em;
            font-weight: 600;
            margin-bottom: 12px;
            display: block;
        }

        .hint {
            padding: 16px;
            background: var(--gal-surface-soft);
            border-left: 3px solid var(--gal-accent);
            border-radius: 0 var(--gal-radius) var(--gal-radius) 0;
            margin: 24px 0;
            font-size: 14px;
            color: var(--gal-ink);
        }

        /* Action Buttons */
        .actions {
            margin-top: 20px;
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }

        button, .button-link {
            font-family: var(--font-sans);
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 10px 18px;
            border-radius: var(--gal-radius);
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-block;
            text-align: center;
            text-decoration: none;
        }

        button:not(.secondary), .button-link:not(.secondary) {
            background: var(--gal-accent);
            color: #FFFFFF;
            border: 1px solid var(--gal-accent);
        }

        button:not(.secondary):hover, .button-link:not(.secondary):hover {
            background: var(--gal-accent-hover);
            border-color: var(--gal-accent-hover);
        }

        button.secondary, .button-link.secondary {
            background: transparent;
            color: var(--gal-ink);
            border: 1px solid var(--gal-border);
        }

        button.secondary:hover, .button-link.secondary:hover {
            background: var(--gal-surface-soft);
            border-color: var(--gal-muted);
        }

        button.secondary.danger {
            color: var(--gal-danger);
            border-color: var(--gal-border);
        }

        button.secondary.danger:hover {
            background: var(--gal-accent-light);
            border-color: var(--gal-danger);
        }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__.'/sidebar.php';?>
    <main class="main-area">
        <div class="workspace sv">
            <h1>Social Video (beta)</h1>
            <p>Connect your existing mockups into a single sequence. Drag them to define the order.</p>
            
            <?php if($notice):?>
                <div class="notice" style="padding: 12px; background: var(--gal-accent-light); border: 1px solid var(--gal-accent); border-radius: var(--gal-radius); margin-bottom: 20px; color: var(--gal-ink); font-weight: 500; font-size: 14px;">
                    <?=sh($notice)?>
                </div>
            <?php endif;?>
            
            <?php if($error):?>
                <div class="notice error" style="padding: 12px; background: #FDE8E8; border: 1px solid var(--gal-danger); border-radius: var(--gal-radius); margin-bottom: 20px; color: var(--gal-danger); font-weight: 500; font-size: 14px;">
                    <?=sh($error)?>
                </div>
            <?php endif;?>
            
            <div class="hint">
                There will be <?=max(0,count($sequence)-1)?> transitions created between the mockups. No new scenes, text, or images will be generated.
            </div>
            
            <form method="post" id="social-video-form">
                <input type="hidden" name="id" value="<?=$id?>">
                <input id="sequence" type="hidden" name="mockup_sequence" value="<?=sh(implode(',',$sequence))?>">
                
                <h2 style="font-family: var(--font-serif); font-size: 22px; font-weight: 500; margin: 24px 0 8px;">Mockup Sequence Order</h2>
                <p style="font-size: 13px; color: var(--gal-muted); margin: 0 0 18px 0; font-style: italic;">Drag the mockup cards to change the display sequence order in the final video.</p>
                
                <div id="mockups" class="mockups">
                    <?php foreach($sequence as $i=>$f):?>
                        <div class="mockup" draggable="true" data-file="<?=sh($f)?>">
                            <div class="number">Mockup <?=($i+1)?></div>
                            <img src="media.php?file=<?=rawurlencode($f)?>">
                        </div>
                    <?php endforeach;?>
                </div>
                
                <div class="actions">
                    <button type="submit" class="secondary" name="action" value="save">Save Order</button>
                    <button type="submit" name="action" value="generate" formaction="generate_social_video.php">Generate Video</button>
                </div>
            </form>
            
            <!-- Video Final Card Section -->
            <?php if($video !== ''):?>
                <h2 style="font-family: var(--font-serif); font-size: 24px; font-weight: 500; margin: 40px 0 16px;">Final Video Sequence</h2>
                <div class="contexts">
                    <article class="card generated">
                        <div class="number">Final Render</div>
                        <h3>Video Sequence</h3>
                        <span class="purpose">9:16 Vertical Render</span>
                        
                        <div class="inline-result inline-result-box" style="margin-bottom: 16px; border: 1px solid var(--gal-border); border-radius: var(--gal-radius); overflow: hidden; background: #000; display: flex; justify-content: center; align-items: center; box-shadow: inset 0 2px 8px rgba(0,0,0,0.2);">
                            <video controls style="width: 100%; height: 340px; object-fit: contain; display: block;" src="media.php?file=<?=rawurlencode($video)?>"></video>
                        </div>
                        
                        <div class="generated-actions" style="display: flex; flex-direction: column; gap: 8px;">
                            <div style="display: flex; gap: 6px;">
                                <a href="media.php?file=<?=rawurlencode($video)?>&download=1" class="button-link" style="flex: 1;">Download</a>
                            </div>
                            <form method="post" action="delete_social_video.php" style="margin: 0; width: 100%;" onsubmit="return confirm('Delete this generated video?')">
                                <input type="hidden" name="id" value="<?=$id?>">
                                <button type="submit" class="secondary danger" style="width: 100%;">Delete Video</button>
                            </form>
                        </div>
                    </article>
                </div>
            <?php endif;?>
        </div>
    </main>
</div>
<script>
const list=document.getElementById('mockups'),out=document.getElementById('sequence');
let drag;
list.querySelectorAll('.mockup').forEach(x=>{
    x.addEventListener('dragstart',()=>{drag=x;x.classList.add('drag')});
    x.addEventListener('dragend',()=>x.classList.remove('drag'));
    x.addEventListener('dragover',e=>e.preventDefault());
    x.addEventListener('drop',e=>{
        e.preventDefault();
        if(drag&&drag!==x){
            list.insertBefore(drag,x);
            out.value=[...list.children].map(n=>n.dataset.file).join(',');
            // Update the mockup labels in the number tags
            list.querySelectorAll('.mockup').forEach((m, idx) => {
                m.querySelector('.number').textContent = 'Mockup ' + (idx + 1);
            });
        }
    })
});
</script>
</body>
</html>
