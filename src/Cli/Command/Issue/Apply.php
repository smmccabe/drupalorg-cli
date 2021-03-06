<?php

namespace mglaman\DrupalOrgCli\Command\Issue;

use Gitter\Client;
use mglaman\DrupalOrg\RawResponse;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class Apply extends IssueCommandBase {

  /**
   * @var \Gitter\Repository
   */
  protected $repository;

  protected $cwd;

  protected function configure()
  {
    $this
      ->setName('issue:apply')
      ->addArgument('nid', InputArgument::REQUIRED, 'The issue node ID')
      ->setDescription('Applies the latest patch from an issue.');
  }

  protected function initialize(InputInterface $input, OutputInterface $output) {
    parent::initialize($input, $output);
    $this->cwd = getcwd();
    try {
      $client = new Client();
      $this->repository = $client->getRepository($this->cwd);
    }
    catch (\Exception $e) {
      $this->repository = null;
    }
  }

  /**
   * {@inheritdoc}
   *
   */
  protected function execute(InputInterface $input, OutputInterface $output)
  {
    $nid = $this->stdIn->getArgument('nid');
    $issue = $this->getNode($nid);

    $patchFileUrl = $this->getPatchFileUrl($issue);
    $patchFileContents = file_get_contents($patchFileUrl);
    $patchFileName = $this->getCleanIssueTitle($issue) . '.patch';
    file_put_contents($patchFileName, $patchFileContents);

    if ($this->repository) {
      $exitCode = $this->applyWithGit($issue, $patchFileName);
    } elseif (shell_exec("command -v patch; echo $?") == 0) {
      $exitCode = $this->applyWithPatch($patchFileName);
    } else {
      $this->stdErr->writeln('This is not a Git repository and the `patch` command is not available.');
      $exitCode = 1;
    }

    unlink($patchFileName);
    return $exitCode;
  }

  protected function applyWithGit($issue, $patchFileName) {
    // Validate the issue versions branch, create or checkout issue branch.
    $issueBranchCommand = $this->getApplication()->find('issue:branch');
    $issueBranchCommand->run($this->stdIn, $this->stdOut);

    $branchName = $this->buildBranchName($issue);
    $tempBranchName = $branchName . '-patch-temp';

    // Check out the root development branch to create a temporary merge branch
    // where we will apply the patch, and then three way merge to existing issue
    // branch.
    $issueVersionBranch = $this->getIssueVersionBranchName($issue);
    $this->repository->checkout($issueVersionBranch);
    $this->stdOut->writeln(sprintf('<comment>%s</comment>', "Creating temp branch $tempBranchName"));
    $this->repository->createBranch($tempBranchName);
    $this->repository->checkout($tempBranchName);

    $applyPatchProcess = $this->runProcess(sprintf('git apply -v --index %s', $patchFileName));
    if ($applyPatchProcess->getExitCode() != 0) {
      $this->stdOut->writeln('<error>Failed to apply the patch</error>');
      $this->stdOut->writeln($applyPatchProcess->getOutput());
      return 1;
    }
    $this->stdOut->writeln(sprintf('<comment>%s</comment>', "Committing $patchFileName"));
    $this->repository->commit($patchFileName);

    // Check out existing issue branch for three way merge.
    $this->stdOut->writeln(sprintf('<comment>%s</comment>', "Checking out $branchName and merging"));
    $this->repository->checkout($branchName);
    $merge = $this->runProcess(sprintf('git merge %s --strategy recursive -X theirs', $tempBranchName));

    if ($merge->getExitCode() != 0) {
      $this->stdOut->writeln('<error>Failed to apply the patch</error>');
      $this->stdOut->writeln($merge->getOutput());
      return 1;
    }

    $this->runProcess(sprintf('git branch -D %s', $tempBranchName));
  }

  protected function applyWithPatch($patchFileName) {
    $process = $this->runProcess(sprintf('patch -p1 < %s', $patchFileName));
    if ($process->getExitCode() != 0) {
      $this->stdOut->writeln('<error>Failed to apply the patch</error>');
      $this->stdOut->writeln($process->getOutput());
      return 1;
    }
  }

  protected function getPatchFileUrl(RawResponse $issue) {
    // Remove files hidden from display.
    $files = array_filter($issue->get('field_issue_files'), function ($value) {
      return (bool) $value->display;
    });
    // Reverse the array so we fetch latest files first.
    $files = array_reverse($files);
    $files = array_map(function ($value) {
      return $this->getFile($value->file->id);
    }, $files);
    // Filter out non-patch files.
    $files = array_filter($files, function (RawResponse $file) {
      return strpos($file->get('name'), '.patch') !== FALSE && strpos($file->get('name'), 'do-not-test') === FALSE;
    });
    $patchFile = reset($files);
    $patchFileUrl = $patchFile->get('url');
    return $patchFileUrl;
  }

}
