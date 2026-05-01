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
        parent::__construct('srt-compare', $this->getVersion());

        // Add the default command
        $compareCommand = new class extends Command {
            protected function configure(): void
            {
                $this
                    ->setName('compare')
                    ->setDescription('Compare two subtitle files')
                    ->addArgument(
                        'file1',
                        InputArgument::OPTIONAL,
                        'First subtitle file'
                    )
                    ->addArgument(
                        'file2',
                        InputArgument::OPTIONAL,
                        'Second subtitle file'
                    )
                    ->addOption(
                        'file',
                        'i',
                        InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                        'Subtitle file (alternative to positional args)'
                    )
                    ->addOption(
                        'update',
                        null,
                        InputOption::VALUE_NONE,
                        'Update the application to the latest version from GitHub'
                    );
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $io = new SymfonyStyle($input, $output);
                
                // Handle the update option
                if ($input->getOption('update')) {
                    return $this->selfUpdate($io);
                }
                
                $files = $input->getOption('file');
                
                // If no -i files provided, check positional arguments
                if (empty($files)) {
                    $file1 = $input->getArgument('file1');
                    $file2 = $input->getArgument('file2');
                    
                    if ($file1 !== null && $file2 !== null) {
                        $files = [$file1, $file2];
                    }
                }

                // Validate that exactly two files are provided
                if ($files === null || count($files) !== 2) {
                    $io->error('Exactly two subtitle files are required. Usage: srt-compare <file1> <file2> or srt-compare -i <file1> -i <file2>');
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
                $ui = new \App\TerminalUI($comparisonData, $file1, $file2);
                $ui->render();
                
                return Command::SUCCESS;
            }
            
            private function selfUpdate(SymfonyStyle $io): int
            {
                $io->title('Self Update');
                
                // Get current version
                $currentVersion = trim(file_get_contents(__DIR__ . '/../VERSION'));
                $io->text(sprintf('Current version: %s', $currentVersion));
                
                // Get remote version from GitHub
                $remoteVersionUrl = 'https://raw.githubusercontent.com/iceman1010/srt-compare/main/VERSION';
                $ch = curl_init($remoteVersionUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_USERAGENT, 'srt-compare-self-update');
                $remoteVersionContent = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($httpCode !== 200) {
                    $io->error('Failed to check for updates. Please try again later.');
                    return Command::FAILURE;
                }
                
                $remoteVersion = trim($remoteVersionContent);
                if (empty($remoteVersion)) {
                    $io->error('Invalid response from GitHub.');
                    return Command::FAILURE;
                }
                
                $io->text(sprintf('Latest version: %s', $remoteVersion));
                
                if (version_compare($remoteVersion, $currentVersion, '>')) {
                    $io->text('Update available! Downloading...');
                    
                    // Get the latest workflow run for the build-phar workflow
                    $workflowRunsUrl = 'https://api.github.com/repos/iceman1010/srt-compare/actions/workflows/build-phar.yml/runs?branch=main&per_page=1';
                    $ch = curl_init($workflowRunsUrl);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_USERAGENT, 'srt-compare-self-update');
                    $githubToken = getenv('GITHUB_TOKEN');
                    if ($githubToken) {
                        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: token ' . $githubToken]);
                    }
                    $workflowResponse = curl_exec($ch);
                    $workflowHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    
                    if ($workflowHttpCode !== 200) {
                        $io->error('Failed to check for workflow runs. Please try again later.');
                        return Command::FAILURE;
                    }
                    
                    $workflowData = json_decode($workflowResponse, true);
                    if (json_last_error() !== JSON_ERROR_NONE || empty($workflowData['workflow_runs'])) {
                        $io->error('No workflow runs found.');
                        return Command::FAILURE;
                    }
                    
                    $run = $workflowData['workflow_runs'][0];
                    if ($run['conclusion'] !== 'success') {
                        $io->error('Latest workflow run was not successful.');
                        return Command::FAILURE;
                    }
                    
                    // Get artifacts for this run
                    $artifactsUrl = $run['artifacts_url'];
                    $ch = curl_init($artifactsUrl);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_USERAGENT, 'srt-compare-self-update');
                    if ($githubToken) {
                        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: token ' . $githubToken]);
                    }
                    $artifactsResponse = curl_exec($ch);
                    $artifactsHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    
                    if ($artifactsHttpCode !== 200) {
                        $io->error('Failed to get artifacts. Please try again later.');
                        return Command::FAILURE;
                    }
                    
                    $artifactsData = json_decode($artifactsResponse, true);
                    if (json_last_error() !== JSON_ERROR_NONE || empty($artifactsData['artifacts'])) {
                        $io->error('No artifacts found.');
                        return Command::FAILURE;
                    }
                    
                    $pharAsset = null;
                    foreach ($artifactsData['artifacts'] as $artifact) {
                        if ($artifact['name'] === 'srt-compare-phar') {
                            $pharAsset = $artifact;
                            break;
                        }
                    }
                    
                    if (!$pharAsset) {
                        $io->error('PHAR artifact not found.');
                        return Command::FAILURE;
                    }
                    
                    // Download the artifact
                    $downloadUrl = $pharAsset['archive_download_url'];
                    $tempZip = sys_get_temp_dir() . '/srt-compare-update.zip';
                    $io->progressStart(100);
                    
                    $ch = curl_init($downloadUrl);
                    $fp = fopen($tempZip, 'wb');
                    
                    curl_setopt($ch, CURLOPT_FILE, $fp);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                    curl_setopt($ch, CURLOPT_USERAGENT, 'srt-compare-self-update');
                    
                    if ($githubToken) {
                        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: token ' . $githubToken]);
                    }
                    
                    // Progress callback
                    curl_setopt($ch, \CURLOPT_PROGRESSFUNCTION, function($ch, $dltotal, $dlnow, $ultotal, $ulnow) use ($io) {
                        if ($dltotal > 0) {
                            $progress = intval(($dlnow / $dltotal) * 100);
                            $io->progressAdvance($progress - $io->getProgressStep());
                        }
                        return 0;
                    });
                    
                    curl_setopt($ch, CURLOPT_NOPROGRESS, false);
                    
                    $result = curl_exec($ch);
                    $curlError = curl_error($ch);
                    curl_close($ch);
                    fclose($fp);
                    
                    $io->progressFinish();
                    
                    if ($curlError) {
                        $io->error('Download failed: ' . $curlError);
                        @unlink($tempZip);
                        return Command::FAILURE;
                    }
                    
                    if (!$result) {
                        $io->error('Download failed.');
                        @unlink($tempZip);
                        return Command::FAILURE;
                    }
                    
                    // Extract the PHAR from the zip
                    $tempPhar = sys_get_temp_dir() . '/srt-compare.phar';
                    $zip = new ZipArchive();
                    if ($zip->open($tempZip) !== true) {
                        $io->error('Failed to open ZIP file.');
                        @unlink($tempZip);
                        return Command::FAILURE;
                    }
                    
                    // Extract the PHAR file (we know it's named srt-compare.phar in the zip)
                    if (!$zip->extractTo(sys_get_temp_dir(), ['srt-compare.phar'])) {
                        $io->error('Failed to extract PHAR from ZIP.');
                        $zip->close();
                        @unlink($tempZip);
                        return Command::FAILURE;
                    }
                    $zip->close();
                    @unlink($tempZip);
                    
                    if (!file_exists($tempPhar)) {
                        $io->error('Extracted PHAR not found.');
                        return Command::FAILURE;
                    }
                    
                    // Make executable
                    chmod($tempPhar, 0755);
                    
                    // Replace current PHAR
                    $currentPhar = __DIR__ . '/../srt-compare.phar';
                    if (rename($tempPhar, $currentPhar)) {
                        // Update VERSION file
                        file_put_contents(__DIR__ . '/../VERSION', $remoteVersion);
                        $io->success(sprintf('Updated to version %s!', $remoteVersion));
                        $io->text('Please restart the application to use the new version.');
                        return Command::SUCCESS;
                    } else {
                        $io->error('Failed to replace the current PHAR file.');
                        @unlink($tempPhar);
                        return Command::FAILURE;
                    }
                } else {
                    $io->success('You are already running the latest version!');
                    return Command::SUCCESS;
                }
            }
        };

        $this->add($compareCommand);
        $this->setDefaultCommand('compare', true);
    }
    
    public function getVersion(): string
    {
        $versionFile = __DIR__ . '/../VERSION';
        if (file_exists($versionFile)) {
            return trim(file_get_contents($versionFile));
        }
        return '1.0.0';
    }
}