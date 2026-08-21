<?php

namespace App\Enums;

enum AiOperation: string
{
    case SpellCheck = 'spell_check';
    case Improve = 'improve';
    case Rewrite = 'rewrite';
    case Shorten = 'shorten';
    case Expand = 'expand';
    case Simplify = 'simplify';
    case OfficialNews = 'official_news';
    case Advertisement = 'advertisement';
    case AcademicFormat = 'academic_format';
    case MediaFormat = 'media_format';
    case SuggestTitles = 'suggest_titles';
    case SuggestClosing = 'suggest_closing';
    case SuggestCallToAction = 'suggest_call_to_action';
    case SuggestHashtags = 'suggest_hashtags';
    case AddEmojis = 'add_emojis';
    case Translate = 'translate';
    case AdaptPlatforms = 'adapt_platforms';

    public function instruction(): string
    {
        return match ($this) {
            self::SpellCheck => 'Correct spelling, clear grammar, Arabic hamzas, punctuation, and spacing only. Preserve the author\'s wording wherever it is already correct.',
            self::Improve => 'Improve clarity and flow while preserving every fact and the author\'s meaning.',
            self::Rewrite => 'Rewrite for the requested tone while preserving every fact, name, number, date, link, handle, and hashtag.',
            self::Shorten => 'Make the text shorter without dropping facts, names, numbers, dates, links, handles, or hashtags.',
            self::Expand => 'Expand only by clarifying information already present. Do not invent facts, events, statistics, sources, or claims.',
            self::Simplify => 'Use simpler wording while preserving every fact and protected token.',
            self::OfficialNews => 'Rewrite as a concise official news update. Preserve every fact and do not invent a source, quotation, outcome, or publication claim.',
            self::Advertisement => 'Rewrite as a clear advertisement using only supplied facts. Do not invent offers, prices, urgency, availability, or claims.',
            self::AcademicFormat => 'Rewrite in a precise academic register without adding citations, research results, or unsupported claims.',
            self::MediaFormat => 'Rewrite in a factual media style. Preserve all facts and do not invent attribution, quotes, or breaking-news claims.',
            self::SuggestTitles => 'Return 3 to 5 concise title suggestions based only on the supplied text, one suggestion per line.',
            self::SuggestClosing => 'Return 3 concise optional closing lines based only on the supplied text, one per line. Do not state unverified facts.',
            self::SuggestCallToAction => 'Return 3 optional, non-deceptive calls to action based only on the supplied text, one per line.',
            self::SuggestHashtags => 'Return a small set of relevant, non-duplicated hashtags based only on the supplied text, one hashtag per line.',
            self::AddEmojis => 'Add a small, appropriate number of emojis while preserving every fact and protected token. Do not overuse emojis.',
            self::Translate => 'Translate faithfully into the requested language. Preserve names, numbers, dates, links, handles, hashtags, and protected tokens exactly.',
            self::AdaptPlatforms => 'Create a proposed version for the requested platforms. Respect supplied platform names, preserve facts, names, links, dates, handles, hashtags, and never claim the text was published.',
        };
    }
}
