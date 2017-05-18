<?php

namespace Bobey\GitlabIssueByMail\Command;

use Bobey\GitlabIssueByMail\Configuration\ParametersConfiguration;
use Fetch\Message;
use Fetch\Server;
use Gitlab\Client as GitlabClient;
use Gitlab\Model\Project as GitlabProject;
use Html2Text\Html2Text;
use Nette\Utils\Strings;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Parser;

class FetchMailCommand extends Command {
	protected function configure() {
		$this
			->setName('gitlab:fetch-mail')
			->setDescription('Fetch emails from given mail address and create Gitlab Issues from it');
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$yaml = new Parser();

		$config = $yaml->parse(file_get_contents(__DIR__ . '/../../../../config/parameters.yml'));

		$processor = new Processor();
		$configuration = new ParametersConfiguration();
		$processedConfiguration = $processor->processConfiguration($configuration, [$config]);

		// Gitlab parameters
		$token = $processedConfiguration['gitlab']['token'];
		$projectId = $processedConfiguration['gitlab']['projectId'];
		$gitlabUrl = $processedConfiguration['gitlab']['host'];

		// Mail parameters
		$server = $processedConfiguration['mail']['server'];
		$port = $processedConfiguration['mail']['port'];
		$type = $processedConfiguration['mail']['type'];
		$username = $processedConfiguration['mail']['username'];
		$password = $processedConfiguration['mail']['password'];

		$server = new Server($server, $port, $type);
		$server->setAuthentication($username, $password);

		$client = new GitlabClient(sprintf('%s/api/v3/', $gitlabUrl));
		$client->authenticate($token, GitlabClient::AUTH_URL_TOKEN);

		$project = new GitlabProject($projectId, $client);

		/** @var Message[] $messages */
		$messages = $server->getMessages();

		foreach($messages as $message) {

			$issueTitle = iconv_mime_decode($message->getSubject());

			$issueContent = $this->html2text($message->getMessageBody(true));

			$project->createIssue($issueTitle, [
				'description' => $issueContent,
			]);

			if($output->getVerbosity() <= OutputInterface::VERBOSITY_VERBOSE) {
				$output->writeln(sprintf('<info>Created a new issue: <comment>%s</comment></info>', $issueTitle));
			}

			$message->delete();
		}

		$output->writeln(count($messages) ?
			sprintf('<info>Created %d new issue%s</info>', count($messages), count($messages) > 1 ? 's' : '') :
			'<info>No new issue created</info>'
		);

		$server->expunge();
	}

	private function html2text($html) {
		$text = Strings::replace($html, ['#<(style|script|head).*</\\1>#Uis' => '',
			'#<t[dh][ >]#i' => ' $0',
			'#<a\s[^>]*href=(?|"([^"]+)"|\'([^\']+)\')[^>]*>(.*?)</a>#is' => '$2 &lt;$1&gt;',
			'#[\r\n]+#' => ' ',
			'#<(/?p|/?h\d|li|br|/tr)[ >/]#i' => "\n$0",]);
		$text = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
		$text = Strings::replace($text, '#[ \t]+#', ' ');

		return "```\n" . trim($text) . "\n```";
	}
}
