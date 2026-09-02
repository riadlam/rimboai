<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Upsert purpose-focused EN/FR/AR descriptions for Lab catalog models.
 * Never clears FR/AR when a curated row is missing — only writes when we have copy.
 */
class SeedLabModelDescriptions extends Command
{
    protected $signature = 'lab:seed-model-descriptions {--force : Overwrite existing FR/AR even if already set}';

    protected $description = 'Seed helpful EN/FR/AR model descriptions for Lab pickers';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $updated = 0;

        foreach ($this->modelTables() as $table => $keyColumn) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'description_fr')) {
                $this->warn("Skip {$table}");

                continue;
            }

            $rows = DB::table($table)->get(['id', 'endpoint_id', 'name', 'description', 'description_fr', 'description_ar']);
            foreach ($rows as $row) {
                $key = strtolower(trim((string) ($row->{$keyColumn} ?? $row->endpoint_id ?? '')));
                $nameKey = strtolower(trim((string) ($row->name ?? '')));
                $bundle = $this->curated($key) ?? $this->curated($nameKey) ?? $this->heuristic((string) $row->name, $table);

                if ($bundle === null) {
                    continue;
                }

                $payload = [];
                if ($force || blank($row->description) || $this->looksGeneric((string) $row->description)) {
                    $payload['description'] = $bundle['en'];
                }
                if ($force || blank($row->description_fr)) {
                    $payload['description_fr'] = $bundle['fr'];
                }
                if ($force || blank($row->description_ar)) {
                    $payload['description_ar'] = $bundle['ar'];
                }

                if ($payload === []) {
                    continue;
                }

                DB::table($table)->where('id', $row->id)->update($payload);
                $updated++;
            }
        }

        // Voices / examples: light heuristic if empty
        foreach (['text_to_voice_voices', 'text_to_music_examples'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'description_fr')) {
                continue;
            }
            $columns = ['id', 'description', 'description_fr', 'description_ar'];
            if (Schema::hasColumn($table, 'name')) {
                $columns[] = 'name';
            }
            if (Schema::hasColumn($table, 'title')) {
                $columns[] = 'title';
            }
            $rows = DB::table($table)->get($columns);
            foreach ($rows as $row) {
                $label = (string) ($row->name ?? $row->title ?? 'Item');
                if (! blank($row->description_fr) && ! blank($row->description_ar) && ! $force) {
                    continue;
                }
                $bundle = $this->heuristic($label, $table);
                $payload = [];
                if ($force || blank($row->description_fr)) {
                    $payload['description_fr'] = $bundle['fr'];
                }
                if ($force || blank($row->description_ar)) {
                    $payload['description_ar'] = $bundle['ar'];
                }
                if (($force || blank($row->description)) && blank($row->description)) {
                    $payload['description'] = $bundle['en'];
                }
                if ($payload !== []) {
                    DB::table($table)->where('id', $row->id)->update($payload);
                    $updated++;
                }
            }
        }

        $this->info("Updated {$updated} rows.");
        $this->bustCatalogCaches();
        $this->info('Catalog brand caches cleared.');

        return self::SUCCESS;
    }

    private function bustCatalogCaches(): void
    {
        $locales = ['en', 'fr', 'ar'];
        foreach ([
            'text_to_image_models' => 'text_to_image_categories',
            'text_to_video_models' => 'text_to_video_categories',
            'text_to_voice_models' => 'text_to_voice_categories',
            'text_to_music_models' => 'text_to_music_categories',
        ] as $models => $categories) {
            foreach ($locales as $locale) {
                Cache::forget("catalog.brands.v5.{$locale}.{$models}.{$categories}");
            }
            Cache::forget("catalog.brands.v4.{$models}.{$categories}");
            Cache::forget("catalog.brands.v3.{$models}.{$categories}");
        }
    }

    /** @return array<string, string> */
    private function modelTables(): array
    {
        return [
            'text_to_video_models' => 'endpoint_id',
            'text_to_image_models' => 'endpoint_id',
            'text_to_voice_models' => 'endpoint_id',
            'text_to_music_models' => 'endpoint_id',
            'video_tools_models' => 'name',
        ];
    }

    private function looksGeneric(string $text): bool
    {
        $t = strtolower($text);

        return $t === ''
            || str_contains($t, 'generate videos with')
            || str_contains($t, 'generate images with')
            || str_contains($t, 'text-to-video')
            || str_contains($t, 'ai model that');
    }

    /**
     * @return array{en: string, fr: string, ar: string}|null
     */
    private function curated(string $key): ?array
    {
        static $map = null;
        $map ??= $this->buildCuratedMap();

        return $map[$key] ?? null;
    }

    /**
     * @return array<string, array{en: string, fr: string, ar: string}>
     */
    private function buildCuratedMap(): array
    {
        $t = fn (string $en, string $fr, string $ar) => ['en' => $en, 'fr' => $fr, 'ar' => $ar];

        return [
            // Video
            'fal-ai/kling-video/v2.5-turbo/pro/text-to-video' => $t(
                'Fast cinematic Kling clips — great for social drafts when you need speed over maximum detail.',
                'Clips Kling cinématographiques rapides — idéal pour des brouillons sociaux quand la vitesse compte.',
                'مقاطع Kling سينمائية سريعة — ممتازة لمسودات السوشيال عندما تحتاج السرعة أكثر من أقصى تفاصيل.'
            ),
            'fal-ai/kling-video/v2.6/pro/text-to-video' => $t(
                'Balanced Kling quality for product and lifestyle clips. Strong motion with clear subjects.',
                'Qualité Kling équilibrée pour produits et lifestyle. Mouvement solide et sujets nets.',
                'جودة Kling متوازنة لمقاطع المنتجات وأسلوب الحياة. حركة قوية ومواضيع واضحة.'
            ),
            'fal-ai/kling-video/v3/pro/text-to-video' => $t(
                'Latest Kling Pro for premium ads and storytelling. Pick when you want sharper detail and polish.',
                'Dernier Kling Pro pour pubs premium et storytelling. À choisir pour plus de détail et de finition.',
                'أحدث Kling Pro للإعلانات الفاخرة والسرد. اختره عندما تريد تفاصيل أدق ولمسة نهائية أفضل.'
            ),
            'fal-ai/kling-video/o3/pro/text-to-video' => $t(
                'Kling O3 Pro cinematic generation — best for hero shots and brand films that need presence.',
                'Génération ciné Kling O3 Pro — idéale pour plans hero et films de marque.',
                'توليد سينمائي Kling O3 Pro — الأفضل للقطات البارزة وأفلام العلامة.'
            ),
            'fal-ai/kling-video/o3/standard/text-to-video' => $t(
                'Kling O3 Standard — solid everyday video from text when Pro budget is not needed.',
                'Kling O3 Standard — vidéo quotidienne fiable depuis un texte sans budget Pro.',
                'Kling O3 Standard — فيديو يومي موثوق من النص عندما لا تحتاج ميزانية Pro.'
            ),
            'fal-ai/kling-video/o3/4k/reference-to-video' => $t(
                'Native 4K Kling O3 from reference images — keep character/style identity while going ultra sharp.',
                'Kling O3 en 4K natif depuis des images de référence — identité personnage/style en ultra net.',
                'Kling O3 بدقة 4K أصلية من صور مرجعية — يحافظ على هوية الشخصية/الأسلوب بحدة عالية.'
            ),
            'fal-ai/kling-video/o1/reference-to-video' => $t(
                'Reference-to-video with multi-element control — lock face, style, or props via @ references.',
                'Vidéo depuis références multi-éléments — fige visage, style ou objets via @.',
                'فيديو من مراجع متعددة — ثبّت الوجه أو الأسلوب أو العناصر عبر @.'
            ),
            'fal-ai/kling-video/o3/standard/video-to-video/edit' => $t(
                'Swap a face or character in an existing clip using a reference image (@Element1).',
                'Remplace un visage/personnage dans un clip avec une image de référence (@Element1).',
                'استبدل وجهاً أو شخصية في مقطع موجود بصورة مرجعية (@Element1).'
            ),
            'fal-ai/veo3.1' => $t(
                'Google Veo 3.1 — top-tier realism and native audio. Best for premium cinematic spots.',
                'Google Veo 3.1 — réalisme top et audio natif. Idéal pour spots ciné premium.',
                'Google Veo 3.1 — واقعية عالية وصوت مدمج. الأفضل للإعلانات السينمائية الفاخرة.'
            ),
            'fal-ai/veo3.1/fast' => $t(
                'Veo 3.1 Fast — same Google look, quicker/cheaper for iterations before a final Pro render.',
                'Veo 3.1 Fast — le look Google, plus rapide/économique pour itérer avant le final.',
                'Veo 3.1 Fast — نفس مظهر Google بسرعة/تكلفة أقل للتجارب قبل النسخة النهائية.'
            ),
            'fal-ai/veo3.1/lite' => $t(
                'Veo 3.1 Lite — lightweight Google video for quick concepts and drafts.',
                'Veo 3.1 Lite — vidéo Google légère pour concepts et brouillons rapides.',
                'Veo 3.1 Lite — فيديو Google خفيف للمفاهيم والمسودات السريعة.'
            ),
            'bytedance/seedance-2.0/text-to-video' => $t(
                'Seedance 2.0 — lively motion and dance-friendly clips from a text prompt.',
                'Seedance 2.0 — mouvement vivant, parfait pour clips dynamiques depuis un texte.',
                'Seedance 2.0 — حركة حيوية ومقاطع ديناميكية من نص.'
            ),
            'bytedance/seedance-2.0/reference-to-video' => $t(
                'Seedance multimodal references (images/video/audio) — stick to a look while driving motion.',
                'Références multimodales Seedance — garde un look tout en pilotant le mouvement.',
                'مراجع Seedance متعددة الوسائط — حافظ على المظهر مع التحكم بالحركة.'
            ),
            'bytedance/seedance-2.0/fast/reference-to-video' => $t(
                'Faster Seedance reference tier — same multimodal caps when you need quicker turns.',
                'Tier Seedance référence plus rapide — mêmes caps multimodales, tours plus courts.',
                'طبقة Seedance المرجعية الأسرع — نفس الحدود مع نتائج أسرع.'
            ),
            'fal-ai/wan/v2.7/text-to-video' => $t(
                'Wan 2.7 text-to-video — flexible motion and multi-subject references when you branch to R2V.',
                'Wan 2.7 texte→vidéo — mouvement flexible et sujets multiples via la route référence.',
                'Wan 2.7 نص إلى فيديو — حركة مرنة ومواضيع متعددة عبر مسار المراجع.'
            ),
            'fal-ai/wan/v2.2-a14b/image-to-video' => $t(
                'Animate a still into video with Wan 2.2 A14B — strong motion control from one image.',
                'Anime une image fixe avec Wan 2.2 A14B — contrôle de mouvement fort.',
                'حرّك صورة ثابتة بـ Wan 2.2 A14B — تحكم قوي بالحركة من صورة واحدة.'
            ),
            'xai/grok-imagine-video/text-to-video' => $t(
                'Grok Imagine video — playful, imaginative clips when you want bold creative swings.',
                'Vidéo Grok Imagine — clips imaginatifs quand vous voulez oser le créatif.',
                'فيديو Grok Imagine — مقاطع خيالية جريئة عندما تريد إبداعاً جريئاً.'
            ),
            'fal-ai/pixverse/c1/reference-to-video' => $t(
                'PixVerse C1 — character-consistent video using named subject/background references.',
                'PixVerse C1 — vidéo cohérente personnage via références sujet/fond nommées.',
                'PixVerse C1 — فيديو متسق الشخصية بمراجع موضوع/خلفية مسماة.'
            ),
            'google/gemini-omni-flash' => $t(
                'Gemini Omni Flash — all-rounder text-to-video with baked-in audio for fast creative loops.',
                'Gemini Omni Flash — texte→vidéo polyvalent avec audio intégré pour itérer vite.',
                'Gemini Omni Flash — نص إلى فيديو شامل مع صوت مدمج للتجارب السريعة.'
            ),
            'google/gemini-omni-flash/reference-to-video' => $t(
                'Omni Flash from multiple reference images — keep identity while generating new motion.',
                'Omni Flash depuis plusieurs images — garde l’identité avec un nouveau mouvement.',
                'Omni Flash من عدة صور مرجعية — يحافظ على الهوية مع حركة جديدة.'
            ),
            'google/gemini-omni-flash/edit' => $t(
                'Edit an existing Omni video with a prompt — refine action or look without starting over.',
                'Édite une vidéo Omni existante par prompt — affine action/look sans tout refaire.',
                'عدّل فيديو Omni موجود بنص — حسّن الحركة أو المظهر دون البدء من الصفر.'
            ),

            // Image
            'fal-ai/flux-pro/v1.1-ultra' => $t(
                'Flux Pro Ultra — studio stills with rich detail. Ideal for product and campaign key art.',
                'Flux Pro Ultra — images studio très détaillées. Idéal produit et key art campagne.',
                'Flux Pro Ultra — صور استوديو بتفاصيل غنية. مثالي للمنتجات وصور الحملات.'
            ),
            'fal-ai/flux-2/turbo' => $t(
                'Flux 2 Turbo — fast high-quality images for rapid ideation and A/B creatives.',
                'Flux 2 Turbo — images rapides de qualité pour idéation et A/B.',
                'Flux 2 Turbo — صور سريعة عالية الجودة للأفكار واختبارات A/B.'
            ),
            'fal-ai/flux-2-pro' => $t(
                'Flux 2 Pro — premium composition and lighting when the hero image must look finished.',
                'Flux 2 Pro — composition et lumière premium quand l’image hero doit être finale.',
                'Flux 2 Pro — تكوين وإضاءة فاخرة عندما يجب أن تبدو الصورة النهائية جاهزة.'
            ),
            'fal-ai/nano-banana-pro' => $t(
                'Nano Banana Pro — versatile everyday generation; strong default for most prompts.',
                'Nano Banana Pro — génération polyvalente au quotidien ; bon défaut pour la plupart des prompts.',
                'Nano Banana Pro — توليد يومي متعدد الاستخدامات؛ خيار افتراضي قوي لمعظم الأوامر.'
            ),
            'fal-ai/nano-banana-2' => $t(
                'Nano Banana 2 — newer Banana tier with sharper results for social and ads.',
                'Nano Banana 2 — nouvelle génération Banana, plus nette pour social et ads.',
                'Nano Banana 2 — جيل Banana أحدث بنتائج أحدّ للسوشيال والإعلانات.'
            ),
            'fal-ai/nano-banana' => $t(
                'Nano Banana — quick affordable stills when you need volume over maximum fidelity.',
                'Nano Banana — images rapides et abordables quand le volume prime.',
                'Nano Banana — صور سريعة واقتصادية عندما تحتاج كمية أكثر من أقصى دقة.'
            ),
            'fal-ai/nano-banana/edit' => $t(
                'Edit an existing Banana image with a prompt — change style or details without full regen.',
                'Édite une image Banana existante — change style/détails sans tout régénérer.',
                'عدّل صورة Banana موجودة — غيّر الأسلوب أو التفاصيل دون إعادة كاملة.'
            ),
            'fal-ai/nano-banana-pro/edit' => $t(
                'Pro-grade Banana edit — refine a strong base image while keeping composition.',
                'Édition Banana Pro — affine une base solide en gardant la composition.',
                'تعديل Banana Pro — حسّن صورة أساسية قوية مع الحفاظ على التكوين.'
            ),
            'fal-ai/nano-banana-2/edit' => $t(
                'Banana 2 edit route — iterate on a generated still with precise prompt changes.',
                'Route d’édition Banana 2 — itère sur une image avec des changements précis.',
                'مسار تعديل Banana 2 — طوّر صورة مولّدة بتغييرات دقيقة في الأمر.'
            ),
            'fal-ai/gemini-3-pro-image-preview' => $t(
                'Gemini 3 Pro image — excellent prompt following for complex scenes and text-in-image.',
                'Image Gemini 3 Pro — suit très bien les prompts complexes et le texte dans l’image.',
                'صورة Gemini 3 Pro — يتبع الأوامر المعقدة والنص داخل الصورة بدقة.'
            ),
            'fal-ai/gemini-3.1-flash-image-preview' => $t(
                'Gemini 3.1 Flash image — fast Google stills for drafts and high-volume creatives.',
                'Image Gemini 3.1 Flash — images Google rapides pour brouillons et volume.',
                'صورة Gemini 3.1 Flash — صور Google سريعة للمسودات والإنتاج الكثيف.'
            ),
            'fal-ai/gemini-25-flash-image' => $t(
                'Gemini 2.5 Flash image — lightweight Google generation for quick concepts.',
                'Image Gemini 2.5 Flash — génération Google légère pour concepts rapides.',
                'صورة Gemini 2.5 Flash — توليد Google خفيف للمفاهيم السريعة.'
            ),
            'fal-ai/gpt-image-1.5' => $t(
                'GPT Image 1.5 — strong instruction following for layouts, mockups, and UI-like frames.',
                'GPT Image 1.5 — suit bien instructions pour layouts, mockups et cadres type UI.',
                'GPT Image 1.5 — يتبع التعليمات جيداً للتخطيطات والمocks وإطارات شبيهة بالواجهات.'
            ),
            'openai/gpt-image-2' => $t(
                'GPT Image 2 — next-gen OpenAI stills for polished marketing visuals.',
                'GPT Image 2 — images OpenAI nouvelle gen pour visuels marketing soignés.',
                'GPT Image 2 — صور OpenAI الجيل التالي لمرئيات تسويقية مصقولة.'
            ),
            'fal-ai/bytedance/seedream/v4.5/text-to-image' => $t(
                'Seedream 4.5 — vivid commercial looks; great for fashion and lifestyle keyframes.',
                'Seedream 4.5 — looks commerciaux vifs ; idéal mode et lifestyle.',
                'Seedream 4.5 — مظهر تجاري حيوي؛ ممتاز للأزياء وأسلوب الحياة.'
            ),
            'fal-ai/bytedance/seedream/v5/lite/text-to-image' => $t(
                'Seedream 5 Lite — faster Seedream for volume while keeping a modern aesthetic.',
                'Seedream 5 Lite — Seedream plus rapide pour le volume, esthétique moderne.',
                'Seedream 5 Lite — Seedream أسرع للكمية مع جمالية عصرية.'
            ),
            'fal-ai/bytedance/seedream/v4/text-to-image' => $t(
                'Seedream v4 — colorful ByteDance stills for social creatives.',
                'Seedream v4 — images ByteDance colorées pour le social.',
                'Seedream v4 — صور ByteDance ملونة لإبداعات السوشيال.'
            ),
            'fal-ai/kling-image/v3/text-to-image' => $t(
                'Kling Image v3 — cinematic stills that match Kling video style for campaign packs.',
                'Kling Image v3 — images ciné alignées au style Kling vidéo pour packs campagne.',
                'Kling Image v3 — صور سينمائية متوافقة مع أسلوب فيديو Kling لحملات متناسقة.'
            ),
            'xai/grok-imagine-image' => $t(
                'Grok Imagine image — creative, surprising stills when you want a bold concept.',
                'Image Grok Imagine — visuels créatifs et surprenants pour concepts audacieux.',
                'صورة Grok Imagine — صور إبداعية مفاجئة للمفاهيم الجريئة.'
            ),
            'fal-ai/wan/v2.7/text-to-image' => $t(
                'Wan 2.7 image — flexible Asian/global aesthetics for posters and product art.',
                'Image Wan 2.7 — esthétiques flexibles pour posters et art produit.',
                'صورة Wan 2.7 — جماليات مرنة للملصقات وفن المنتجات.'
            ),

            // Voice
            'fal-ai/elevenlabs/tts/eleven-v3' => $t(
                'ElevenLabs v3 — most expressive speech with emotions and inline audio tags (70+ languages).',
                'ElevenLabs v3 — parole la plus expressive, émotions et tags audio (70+ langues).',
                'ElevenLabs v3 — أكثر كلام تعبيرية مع مشاعر ووسوم صوتية (أكثر من 70 لغة).'
            ),
            'fal-ai/elevenlabs/tts/turbo-v2.5' => $t(
                'ElevenLabs Turbo — ultra-low latency voiceovers for drafts and real-time style apps.',
                'ElevenLabs Turbo — voix ultra-rapides pour brouillons et apps temps réel.',
                'ElevenLabs Turbo — تعليقات صوتية فائقة السرعة للمسودات والتطبيقات الفورية.'
            ),
            'fal-ai/gemini-3.1-flash-tts' => $t(
                'Gemini Flash TTS — laughs, sighs, whispers, multi-speaker dialogue from Google.',
                'Gemini Flash TTS — rires, soupirs, chuchotements, dialogue multi-voix Google.',
                'Gemini Flash TTS — ضحك وتنهد وهمس وحوار متعدد المتحدثين من Google.'
            ),
            'fal-ai/minimax/speech-2.8-hd' => $t(
                'MiniMax Speech HD — natural multilingual voices with deep control for polished VO.',
                'MiniMax Speech HD — voix naturelles multilingues avec contrôle fin pour VO soignée.',
                'MiniMax Speech HD — أصوات طبيعية متعددة اللغات بتحكم عميق للتعليق الصوتي.'
            ),
            'fal-ai/minimax/voice-clone' => $t(
                'Clone a voice from a short sample — keep brand talent consistent across videos.',
                'Clonez une voix depuis un court sample — cohérence talent de marque.',
                'استنسخ صوتاً من عينة قصيرة — ثبات صوت العلامة عبر الفيديوهات.'
            ),
            'fal-ai/chatterbox/text-to-speech' => $t(
                'Chatterbox English clone TTS — speak in a cloned voice from your sample.',
                'TTS clone Chatterbox EN — parlez avec une voix clonée depuis votre sample.',
                'Chatterbox استنساخ إنجليزي — تحدّث بصوت مستنسخ من عينتك.'
            ),
            'fal-ai/chatterbox/text-to-speech/multilingual' => $t(
                'Chatterbox multilingual clone — same cloned voice across many languages.',
                'Clone Chatterbox multilingue — même voix clonée sur plusieurs langues.',
                'Chatterbox متعدد اللغات — نفس الصوت المستنسخ عبر لغات كثيرة.'
            ),
            'fal-ai/inworld-tts' => $t(
                'Inworld TTS — characterful game/agent voices with expressive delivery.',
                'Inworld TTS — voix personnages jeux/agents avec delivery expressive.',
                'Inworld TTS — أصوات شخصيات للألعاب/الوكلاء بأداء تعبيري.'
            ),
            'xai/tts/v1' => $t(
                'xAI multilingual TTS — clear cross-language narration for global content.',
                'TTS xAI multilingue — narration claire cross-langue pour contenu global.',
                'xAI TTS متعدد اللغات — سرد واضح عبر اللغات للمحتوى العالمي.'
            ),

            // Music
            'fal-ai/minimax-music/v2.6' => $t(
                'Full songs with singing and arrangement from lyrics + style — social-ready tracks.',
                'Chansons complètes (chant + arrangement) depuis paroles + style — prêtes social.',
                'أغانٍ كاملة بغناء وترتيب من كلمات + أسلوب — جاهزة للسوشيال.'
            ),
            'fal-ai/minimax-music/cover' => $t(
                'Remix or rewrite an uploaded track — new language, style, or vocal character.',
                'Remix/réécriture d’un titre uploadé — nouvelle langue, style ou voix.',
                'أعد مزج أو كتابة مقطع مرفوع — لغة أو أسلوب أو طابع صوتي جديد.'
            ),
            'fal-ai/ace-step/audio-to-audio' => $t(
                'ACE-Step audio edit — transform a source track’s vibe while keeping structure.',
                'Édition audio ACE-Step — transforme l’ambiance d’un titre en gardant la structure.',
                'تعديل ACE-Step — غيّر أجواء مقطع مع الحفاظ على البنية.'
            ),
            'fal-ai/lyria3/pro' => $t(
                'Google Lyria 3 Pro — high-end composition-plan music with section control.',
                'Google Lyria 3 Pro — musique composition-plan haut de gamme, contrôle par sections.',
                'Google Lyria 3 Pro — موسيقى عالية المستوى بخطة تلحين وتحكم بالأقسام.'
            ),
            'fal-ai/elevenlabs/music' => $t(
                'ElevenLabs Music — fast affordable beds and beats with duration control.',
                'ElevenLabs Music — fonds et beats rapides/abordables avec contrôle de durée.',
                'ElevenLabs Music — خلفيات وإيقاعات سريعة واقتصادية مع التحكم بالمدة.'
            ),
            'fal-ai/stable-audio-25/text-to-audio' => $t(
                'Stable Audio 2.5 — long instrumentals and rich sound design from text.',
                'Stable Audio 2.5 — instrumentaux longs et sound design riche depuis un texte.',
                'Stable Audio 2.5 — موسيقى آلية طويلة وتصميم صوتي غني من نص.'
            ),
            'cassetteai/music-generator' => $t(
                'CassetteAI — quick soundtrack beds for shorts and ads.',
                'CassetteAI — fonds sonores rapides pour shorts et pubs.',
                'CassetteAI — خلفيات موسيقية سريعة للقصير والإعلانات.'
            ),

            // Tools (by display name)
            'topaz video upscale' => $t(
                'Upscale footage cleanly with Topaz — recover detail for social and delivery masters.',
                'Upscale propre Topaz — récupère le détail pour social et masters.',
                'رفع دقة Topaz — استرجع التفاصيل للسوشيال والنسخ النهائية.'
            ),
            'topaz artemis enhance' => $t(
                'Topaz Artemis enhance — sharpen and polish video without a harsh look.',
                'Topaz Artemis — netteté et polish vidéo sans rendu agressif.',
                'Topaz Artemis — زيادة وضوح وصقل الفيديو دون مظهر قاسٍ.'
            ),
            'topaz gaia 2 (animation)' => $t(
                'Topaz Gaia 2 — upscale/restore tuned for animation and cartoon footage.',
                'Topaz Gaia 2 — upscale/restauration adaptée à l’animation et au cartoon.',
                'Topaz Gaia 2 — رفع دقة/استعادة مخصص للأنيميشن والكرتون.'
            ),
            'topaz nyx denoise' => $t(
                'Topaz Nyx — remove grain and noise while keeping edges clean.',
                'Topaz Nyx — retire grain/bruit en gardant des bords nets.',
                'Topaz Nyx — أزل الحبيبات والضوضاء مع حواف نظيفة.'
            ),
            'bytedance video upscaler' => $t(
                'ByteDance upscaler — boost resolution for export and platform delivery.',
                'Upscaler ByteDance — monte la résolution pour export et plateformes.',
                'رافع دقة ByteDance — زد الدقة للتصدير ومنصات النشر.'
            ),
            'seedvr2 restore' => $t(
                'SeedVR2 restore — clean up soft or compressed footage.',
                'SeedVR2 restore — nettoie un rush soft ou trop compressé.',
                'SeedVR2 restore — نظّف لقطات ناعمة أو مضغوطة.'
            ),
            'seedvr2 denoise restore' => $t(
                'SeedVR2 denoise — reduce noise then restore clarity.',
                'SeedVR2 denoise — réduit le bruit puis restaure la clarté.',
                'SeedVR2 denoise — قلّل الضوضاء ثم استعد الوضوح.'
            ),
            'seedvr2 anime restore' => $t(
                'SeedVR2 anime restore — recover anime/line-art frames cleanly.',
                'SeedVR2 anime — restaure proprement anime / line-art.',
                'SeedVR2 أنمي — استعادة نظيفة لإطارات الأنمي والخطوط.'
            ),
            'sync 3 (4k lipsync)' => $t(
                'Sync 3 — 4K lipsync to match mouth motion to a new audio track.',
                'Sync 3 — lipsync 4K pour caler la bouche sur une nouvelle piste audio.',
                'Sync 3 — مزامنة شفاه 4K لتطابق حركة الفم مع صوت جديد.'
            ),
            'veed lipsync v2' => $t(
                'VEED Lipsync — quick dubbing sync for talking-head videos.',
                'VEED Lipsync — sync de doublage rapide pour talking-head.',
                'VEED Lipsync — مزامنة دبلجة سريعة لفيديوهات المتحدث.'
            ),
            'bria background removal' => $t(
                'Remove video background with Bria — isolate subject for compositing.',
                'Retire le fond vidéo avec Bria — isole le sujet pour compositing.',
                'أزل خلفية الفيديو بـ Bria — افصل الموضوع للتركيب.'
            ),
            'veed video background removal' => $t(
                'VEED background removal — fast green-screen-free cutouts.',
                'Retrait de fond VEED — découpes rapides sans fond vert.',
                'إزالة خلفية VEED — قص سريع بدون شاشة خضراء.'
            ),
            'mmaudio v2' => $t(
                'MMAudio — generate a matching soundtrack/SFX bed for a silent clip.',
                'MMAudio — génère une bande son/SFX adaptée à un clip muet.',
                'MMAudio — أنشئ مقطعاً صوتياً/مؤثرات تناسب فيديو صامت.'
            ),
            'ltx 2.3 extend' => $t(
                'Extend a clip forward in time — continue motion past the original ending.',
                'Prolonge un clip dans le temps — continue le mouvement après la fin.',
                'مدّد المقطع زمنياً — تابع الحركة بعد النهاية الأصلية.'
            ),
            'pixverse v6 extend' => $t(
                'PixVerse extend — lengthen a shot while keeping style continuity.',
                'PixVerse extend — allonge un plan en gardant le style.',
                'PixVerse extend — أطِل اللقطة مع استمرار الأسلوب.'
            ),
            'pixverse swap' => $t(
                'PixVerse Swap — replace a person/object in video with a reference look.',
                'PixVerse Swap — remplace personne/objet par une référence visuelle.',
                'PixVerse Swap — استبدل شخصاً/عنصراً بمظهر مرجعي.'
            ),
            'bria video erase (prompt)' => $t(
                'Erase objects from video with a text prompt — clean plates for ads.',
                'Efface des objets à l’aide d’un prompt — plaques propres pour pubs.',
                'امحِ عناصر من الفيديو بنص — خلفيات نظيفة للإعلانات.'
            ),
            'void video inpainting' => $t(
                'Inpaint regions of a video — remove or replace areas across frames.',
                'Inpainting vidéo — retire ou remplace des zones sur les frames.',
                'طلاء داخلي للفيديو — أزل أو استبدل مناطق عبر الإطارات.'
            ),
        ];
    }

    /**
     * @return array{en: string, fr: string, ar: string}
     */
    private function heuristic(string $name, string $table): array
    {
        $n = trim($name) !== '' ? trim($name) : 'This model';

        return match (true) {
            str_contains($table, 'image') => [
                'en' => "{$n} — generate stills for ads, posts, and concept art. Pick when this look fits your brief.",
                'fr' => "{$n} — créez des images pour pubs, posts et concepts. À choisir si ce rendu colle au brief.",
                'ar' => "{$n} — أنشئ صوراً للإعلانات والمنشورات والمفاهيم. اختره عندما يناسب أسلوبك.",
            ],
            str_contains($table, 'voice') => [
                'en' => "{$n} — natural voiceovers for videos and apps. Use when this voice style matches your brand.",
                'fr' => "{$n} — voix off naturelles pour vidéos et apps. Quand ce style colle à la marque.",
                'ar' => "{$n} — تعليقات صوتية طبيعية للفيديو والتطبيقات. عندما يناسب أسلوب علامتك.",
            ],
            str_contains($table, 'music') => [
                'en' => "{$n} — soundtrack beds and songs for shorts. Pick for this genre/energy.",
                'fr' => "{$n} — fonds et chansons pour shorts. À choisir pour ce genre/énergie.",
                'ar' => "{$n} — خلفيات وأغانٍ للقصير. اختره لهذا النوع/الطاقة.",
            ],
            str_contains($table, 'tools') => [
                'en' => "{$n} — process an existing video. Use this tool when you need this specific fix.",
                'fr' => "{$n} — traite une vidéo existante. Pour ce correctif précis.",
                'ar' => "{$n} — عالج فيديو موجوداً. عندما تحتاج هذا الإصلاح تحديداً.",
            ],
            default => [
                'en' => "{$n} — create video from your prompt. Choose when you want this model’s look and motion.",
                'fr' => "{$n} — créez une vidéo depuis votre prompt. Pour ce look et ce mouvement.",
                'ar' => "{$n} — أنشئ فيديو من أمرك. عندما تريد مظهر وحركة هذا النموذج.",
            ],
        };
    }
}
