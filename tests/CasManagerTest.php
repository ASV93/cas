<?php

namespace Subfission\Cas\Tests;

use Faker\Factory;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Subfission\Cas\CasManager;
use PHPUnit\Framework\TestCase;
use Subfission\Cas\PhpCasProxy;
use Subfission\Cas\PhpSessionProxy;

class CasManagerTest extends TestCase
{
    /**
     * @var MockObject|PhpCasProxy|PhpCasProxy&MockObject
     */
    private $casProxy;
    /**
     * @var MockObject|PhpSessionProxy|PhpSessionProxy&MockObject
     */
    private $sessionProxy;
    /**
     * @var \Faker\Generator
     */
    private $faker;

    public function setUp(): void
    {
        parent::setUp();

        $this->casProxy = $this->createMock(PhpCasProxy::class);
        $this->sessionProxy = $this->createMock(PhpSessionProxy::class);

        $this->faker = Factory::create();
    }

    public function testDoesNotSetLoggerIfNotProvided(): void
    {
        $this->casProxy->expects($this->never())->method('setLogger');

        $this->makeCasManager();
    }

    public function testSetsLoggerWhenLoggerIsProvided(): void
    {
        $logger = $this->createMock(LoggerInterface::class);

        $this->casProxy->expects($this->once())->method('setLogger')
            ->with($this->equalTo($logger));

        $this->makeCasManager([], $logger);
    }

    /**
     * @dataProvider setVerboseChecks
     */
    public function testSetsVerbose(bool $verbose): void
    {
        $this->casProxy->expects($this->once())->method('setVerbose')
            ->with($this->equalTo($verbose));

        $this->makeCasManager(['cas_verbose_errors' => $verbose]);
    }

    public function setVerboseChecks(): array
    {
        return [
            'verbose' => [true],
            'not verbose' => [false],
        ];
    }

    /**
     * @dataProvider setUpSessionChecks
     */
    public function testSetsUpSessionIfNeeded(bool $headersSent, string $sessionId, bool $shouldSetSession): void
    {
        $this->sessionProxy->expects($this->once())
            ->method('headersSent')
            ->willReturn($headersSent);

        $this->sessionProxy->expects($headersSent ? $this->never() : $this->once())
            ->method('sessionGetId')
            ->willReturn($sessionId);

        $this->sessionProxy->expects($shouldSetSession ? $this->once() : $this->never())
            ->method('sessionSetName');

        $this->sessionProxy->expects($shouldSetSession ? $this->once() : $this->never())
            ->method('sessionSetCookieParams');

        $this->makeCasManager();
    }

    public function setUpSessionChecks(): array
    {
        return [
            'headers not sent, no session id' => [false, '', true],
            'headers not sent, session id' => [false, 'abc123', false],
            'headers sent, no session id' => [true, '', false],
            'headers sent, session id' => [true, 'abc123', false],
        ];
    }

    /**
     * @dataProvider configureCasChecks
     */
    public function testConfiguresCasWithoutSaml(bool $proxy, string $version): void
    {
        $serverType = $proxy ? 'proxy' : 'client';
        $notServerType = $proxy ? 'client' : 'proxy';

        $config = [
            'cas_proxy' => $proxy,
            'cas_version' => $version,
            'cas_enable_saml' => false,
        ];

        $this->casProxy->expects($this->once())->method('serverTypeCas')
            ->with($this->equalTo($version))
            ->willReturn($version);

        $this->casProxy->expects($this->once())->method($serverType)
            ->with($this->equalTo($version));

        $this->casProxy->expects($this->never())->method($notServerType);

        $this->casProxy->expects($this->never())->method('handleLogoutRequests');

        $this->makeCasManager($config);
    }

    /**
     * @dataProvider configureCasChecks
     */
    public function testConfiguresCasWithSaml(bool $proxy, string $version): void
    {
        $serverType = $proxy ? 'proxy' : 'client';
        $notServerType = $proxy ? 'client' : 'proxy';

        $config = [
            'cas_proxy' => $proxy,
            'cas_version' => $version,
            'cas_enable_saml' => true,
        ];

        $this->casProxy->expects($this->once())->method('serverTypeSaml')
            ->willReturn('S1');

        $this->casProxy->expects($this->once())->method($serverType)
            ->with($this->equalTo('S1'));

        $this->casProxy->expects($this->never())->method($notServerType);

        $this->casProxy->expects($this->once())->method('handleLogoutRequests');

        $this->makeCasManager($config);
    }

    public function configureCasChecks(): array
    {
        return [
            'client' => [false, '2.0'],
            'proxy' => [true, '2.0'],
        ];
    }

    public function testConfiguresCasWithClientArguments(): void
    {
        $config = [
            'cas_enable_saml' => false,
            'cas_hostname' => $this->faker->domainName(),
            'cas_port' => $this->faker->numberBetween(1, 1024),
            'cas_uri' => $this->faker->url(),
            'cas_client_service' => $this->faker->url(),
            'cas_control_session' => $this->faker->boolean(),
        ];

        $this->casProxy->expects($this->once())->method('serverTypeCas')
            ->willReturnArgument(0);

        $this->casProxy->expects($this->once())->method('client')
            ->with(
                $this->anything(),
                $this->equalTo($config['cas_hostname']),
                $this->equalTo($config['cas_port']),
                $this->equalTo($config['cas_uri']),
                $this->equalTo($config['cas_client_service']),
                $this->equalTo($config['cas_control_session'])
            );

        $this->makeCasManager($config);
    }

    public function testConfiguresLogoutHandlingWhenUsingSaml(): void
    {
        $realhosts = [
            $this->faker->domainName(),
            $this->faker->domainName(),
        ];

        $config = [
            'cas_enable_saml' => true,
            'cas_real_hosts' => implode(',', $realhosts),
        ];

        $this->casProxy->expects($this->once())->method('handleLogoutRequests')
            ->with(
                $this->equalTo(true),
                $this->equalTo($realhosts)
            );

        $this->makeCasManager($config);
    }

    /**
     * @dataProvider casValidationChecks
     */
    public function testConfiguresCasValidation(?string $casValidation, bool $willValidate): void
    {
        $config = [
            'cas_validation' => $casValidation,
            'cas_cert' => $this->faker->filePath(),
            'cas_validate_cn' => $this->faker->boolean(),
        ];

        if ($willValidate) {
            $this->casProxy->expects($this->never())->method('setNoCasServerValidation');
            $this->casProxy->expects($this->once())->method('setCasServerCACert')
                ->with($this->equalTo($config['cas_cert']), $this->equalTo($config['cas_validate_cn']));
        } else {
            $this->casProxy->expects($this->once())->method('setNoCasServerValidation');
            $this->casProxy->expects($this->never())->method('setCasServerCACert');
        }

        $this->makeCasManager($config);
    }

    public function casValidationChecks(): array
    {
        return [
            'no validation' => [null, false],
            'ca validation' => ['ca', true],
            'self validation' => ['self', true],
        ];
    }

    public function testSetsServerLoginUrl(): void
    {
        $config = [
            'cas_login_url' => $this->faker->url(),
        ];

        $this->casProxy->expects($this->once())->method('setServerLoginURL')
            ->with($this->equalTo($config['cas_login_url']));

        $this->makeCasManager($config);
    }

    public function testSetsServerLogoutUrl(): void
    {
        $config = [
            'cas_logout_url' => $this->faker->url(),
        ];

        $this->casProxy->expects($this->once())->method('setServerLogoutURL')
            ->with($this->equalTo($config['cas_logout_url']));

        $this->makeCasManager($config);
    }

    /**
     * @dataProvider fixedServiceUrlChecks
     */
    public function testSetsFixedServiceUrlIfGiven(bool $willSet): void
    {
        $config = [
            'cas_redirect_path' => $willSet ? $this->faker->url() : null
        ];

        if ($willSet) {
            $this->casProxy->expects($this->once())->method('setFixedServiceURL')
                ->with($this->equalTo($config['cas_redirect_path']));
        } else {
            $this->casProxy->expects($this->never())->method('setFixedServiceURL');
        }

        $this->makeCasManager($config);
    }

    public function fixedServiceUrlChecks(): array
    {
        return [
            'no url' => [false],
            'url' => [true],
        ];
    }

    /**
     * @dataProvider masqueradeChecks
     */
    public function testSetsMasquerade(bool $masquerade): void
    {
        $config = [
            'cas_masquerade' => $masquerade
        ];

        $manager = $this->makeCasManager($config);

        $this->assertEquals($masquerade, $manager->isMasquerading());
    }

    public function masqueradeChecks(): array
    {
        return [
            'masquerade' => [true],
            'no masquerade' => [false],
        ];
    }

    private function makeCasManager(array $config = [], LoggerInterface $logger = null): CasManager
    {
        return new CasManager($config, $logger, $this->casProxy, $this->sessionProxy);
    }
}
