<?php

namespace SilverStripe\UserDocs\Task;

use Override;
use SilverStripe\Dev\BuildTask;
use SilverStripe\i18n\i18n;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\UserDocs\Admin\UserDocsManifest;
use SilverStripe\UserDocs\Search\SearchIndex;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Builds the search indexes for user documentation.
 * Without running this task, the first search in the CMS will build the indexes.
 *
 * Note that this goes through all locales that have documentation and builds their indexes
 * which may result in mmore indexes than your content authors will actually use.
 */
class IndexDocumentationTask extends BuildTask
{
    protected static string $commandName = 'userdocs-search-index';

    protected string $title = 'Build user documentation search index';

    protected static string $description = 'Builds the user documentation search index for every locale that has'
        . ' documentation';

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $searchIndex = SearchIndex::singleton();
        if (!$searchIndex->isSupported()) {
            $output->writeln('<error>Searching documentation is not supported on this server</error>');
            return Command::INVALID;
        }
        $manifest = UserDocsManifest::singleton();
        $manifest->init();
        if ($input->getOption('clear')) {
            $output->writeln('Clearing the search index');
            $searchIndex->clearIndex();
        }
        // Each locale is indexed with its fallback locales, the same as when a user of that locale searches
        foreach (array_keys($manifest->getDocData()) as $locale) {
            $output->writeln("Building the search index for the $locale locale");
            i18n::with_locale((string) $locale, fn () => $searchIndex->init());
        }
        return Command::SUCCESS;
    }

    public function getOptions(): array
    {
        return [
            new InputOption(
                'clear',
                null,
                InputOption::VALUE_NEGATABLE,
                'Clear the search index before building it. Without clearing, only missing indexes are built.'
                    . ' <comment>[default: true]</comment>',
                true
            ),
        ];
    }

    #[Override]
    public static function getHelp(): string
    {
        return <<<'EOD'
        Without running this task, the first search in the CMS will build the indexes.
        Note that this goes through all locales that have documentation and builds their indexes,
        which may result in mmore indexes than your content authors will actually use.
        EOD;
    }
}
