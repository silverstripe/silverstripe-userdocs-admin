---
title: Language settings
summary: Configure stop words and stemming for each language
---

# Language settings

## Stop words

Stop words are common words such as "the" and "is" that don't help to find relevant pages. They are left out of the search index, and they are ignored in the title filter, in the summary for each search result, and in spelling suggestions.

Stop words are set in the [`SearchIndex.stop_words`](api:SilverStripe\UserDocs\Search\SearchIndex->stop_words) configuration array, keyed by locale (e.g. `en_NZ` or `en`). This module has a default list of English stop words for the `en` key.

Locales fall back to their short form - e.g. if there's no stop words in the `en_NZ` locale, it'll fall back to using stop words from `en`.

Stop words should always be added lowercase.

To add stop words to a list, add them in YAML configuration:

```yml
SilverStripe\UserDocs\Search\SearchIndex:
  stop_words:
    en:
      - 'please'
    de:
      - 'der'
      - 'die'
      - 'das'
```

## Stemming

Stemming lets a search find other forms of the words in the search query. A stemmer reduces each word to its stem, so that related words have the same stem. When someone searches, each word in the query also matches the indexed words that have the same stem.

For example, a search for "publishing" also finds pages that only use "publish" or "published".

### Stemmers

Stemmers are set in the [`SearchIndex.stemmers`](api:SilverStripe\UserDocs\Search\SearchIndex->stemmers) configuration array, keyed by short form locale (e.g. `en`, never `en_NZ`). Each value is the name of a class that implements `TeamTNT\TNTSearch\Stemmer\StemmerInterface`.

This module sets the stemmers that TNTSearch includes for Arabic, Croatian, English, French, German, Italian, Latvian, Polish, Portuguese, Russian and Ukrainian. A locale with no stemmer matches only the exact words in the search query.

To use a different stemmer, or to add a stemmer for another language, set it in YAML configuration:

```yml
SilverStripe\UserDocs\Search\SearchIndex:
  stemmers:
    es: 'App\Search\SpanishStemmer'
```

To turn off stemming for a language, set its stemmer to `null`:

```yml
SilverStripe\UserDocs\Search\SearchIndex:
  stemmers:
    de: null
```

### Stemmer aliases

Stemmers sometimes give different stems to related words. For example, the porter stemmer for English gives "add" the stem "add", but gives "adding" and "added" the stem "ad". A search for "add" does not find "adding" unless you fix this with an alias.

Stemmer aliases are set in the [`SearchIndex.stemmer_aliases`](api:SilverStripe\UserDocs\Search\SearchIndex->stemmer_aliases) configuration array, keyed by short form locale. This module has a default list of aliases for the `en` key.

In each alias, both the key and the value are stems, not words:

- The key is the incorrect stem, which the stemmer gives to some of the words.
- The value is the correct stem, which the stemmer gives to the other related words.

```yml
SilverStripe\UserDocs\Search\SearchIndex:
  stemmer_aliases:
    en:
      # "adding" and "added" have the stem "ad", and "add" has the stem "add"
      ad: 'add'
      # "creation" has the stem "creation", and "create" and "creating" have the stem "creat"
      creation: 'creat'
```

A stem is often not a full word, as with "creat" above. To get the stem of a word, call the `stem()` method of the stemmer class, for example `PorterStemmer::stem('creating')`.

Only add aliases for words with the same meaning. If you join stems for words with different meanings, searches for those words will find pages that are not relevant.

> [!WARNING]
> This should not be used for word aliases, such as trying to make searches for "create" also find "add" in documentation.
