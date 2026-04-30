<?php

namespace App;

use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Subtitles\Exception\InvalidSubtitleException;

class Application extends ConsoleApplication
{
    public function __construct()
    {
        parent::__construct('srt-compare', '1.0.0');

        // Add the default command
        $compareCommand = new class extends Command {
            protected function configure(): void
            {
                $this
                    ->setName('compare')
                    ->setDescription('Compare two subtitle files')
                    ->addOption(
                        'file',
                        'i',
                        InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                        'Subtitle file'
                    );
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $io = new SymfonyStyle($input, $output);
                
                $files = $input->getOption('file');

                // Validate that exactly two files are provided
                if ($files === null || count($files) !== 2) {
                    $io->error('Exactly two subtitle files are required. Use -i <file1> -i <file2>');
                    return Command::FAILURE;
                }
                
                list($file1, $file2) = $files;

                // Validate files exist
                if (!is_readable($file1)) {
                    $io->error(sprintf('File not found or not readable: %s', $file1));
                    return Command::FAILURE;
                }
                
                if (!is_readable($file2)) {
                    $io->error(sprintf('File not found or not readable: %s', $file2));
                    return Command::FAILURE;
                }

                $io->title('Subtitle Compare');
                $io->text(sprintf('Comparing %s with %s', $file1, $file2));
                
                // Load and compare subtitles
                try {
                    $comparator = new \App\SubtitleComparator($file1, $file2);
                    $comparisonData = $comparator->getComparisonData();
                } catch (InvalidSubtitleException $e) {
                    $io->error(sprintf('Invalid subtitle file: %s', $e->getMessage()));
                    return Command::FAILURE;
                } catch (\Exception $e) {
                    $io->error(sprintf('Error loading subtitle files: %s', $e->getMessage()));
                    return Command::FAILURE;
                }
                
                // Render UI
                $ui = new \App\TerminalUI($comparisonData);
                $ui->render();
                
                return Command::SUCCESS;
            }
        };

        $this->add($compareCommand);
        $this->setDefaultCommand('compare', true);
    }
}