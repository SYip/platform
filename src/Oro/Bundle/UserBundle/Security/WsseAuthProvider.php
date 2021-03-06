<?php

namespace Oro\Bundle\UserBundle\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\AdvancedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

use Doctrine\Common\Collections\Collection;

use Escape\WSSEAuthenticationBundle\Security\Core\Authentication\Provider\Provider;

use Oro\Bundle\UserBundle\Entity\User;
use Oro\Bundle\UserBundle\Entity\UserApi;

/**
 * Class WsseAuthProvider
 * This override needed to use random generated API key for WSSE auth instead regular user password.
 * In order to prevent usage of user password in third party software.
 * In case if not ORO user is used this provider fallback to native behavior.
 *
 * @package Oro\Bundle\UserBundle\Security
 */
class WsseAuthProvider extends Provider
{
    /**
     * @var WsseTokenFactoryInterface
     */
    protected $tokenFactory;

    /**
     * @param WsseTokenFactoryInterface $tokenFactory
     */
    public function setTokenFactory(WsseTokenFactoryInterface $tokenFactory)
    {
        $this->tokenFactory = $tokenFactory;
    }

    /**
     * {@inheritdoc}
     */
    protected function getSecret(UserInterface $user)
    {
        if ($user instanceof AdvancedApiUserInterface) {
            return $user->getApiKeys();
        }

        return parent::getSecret($user);
    }

    /**
     * {@inheritdoc}
     */
    protected function getSalt(UserInterface $user)
    {
        if ($user instanceof AdvancedApiUserInterface) {
            return '';
        }

        return parent::getSalt($user);
    }

    /**
     * {@inheritdoc}
     */
    public function authenticate(TokenInterface $token)
    {
        if (null === $this->tokenFactory) {
            throw new AuthenticationException('Token Factory is not set in WsseAuthProvider.');
        }

        /** @var User $user */
        $user = $this->getUserProvider()->loadUserByUsername($token->getUsername());
        if ($user) {
            if ($user instanceof AdvancedUserInterface && !$user->isEnabled()) {
                throw new BadCredentialsException('User is not active.');
            }
            $secret = $this->getSecret($user);
            if ($secret instanceof Collection) {
                $validUserApi = $this->getValidUserApi($token, $secret, $user);
                if ($validUserApi) {
                    $authenticatedToken = $this->tokenFactory->create($user->getRoles());
                    $authenticatedToken->setUser($user);
                    $authenticatedToken->setOrganizationContext($validUserApi->getOrganization());
                    $authenticatedToken->setAuthenticated(true);

                    return $authenticatedToken;
                }
            } else {
                return parent::authenticate($token);
            }
        }

        throw new AuthenticationException('WSSE authentication failed.');
    }

    /**
     * Get valid UserApi for given token
     *
     * @param TokenInterface       $token
     * @param Collection $secrets
     * @param User                 $user
     *
     * @return bool|UserApi
     */
    protected function getValidUserApi(TokenInterface $token, Collection $secrets, User $user)
    {
        $currentIteration = 0;
        $nonce            = $token->getAttribute('nonce');
        $secretsCount     = $secrets->count();

        /** @var UserApi $userApi */
        foreach ($secrets as $userApi) {
            $currentIteration++;
            $secret = $userApi->getApiKey();
            $isSecretValid = false;
            if ($secret) {
                $isSecretValid = $this->validateDigest(
                    $token->getAttribute('digest'),
                    $nonce,
                    $token->getAttribute('created'),
                    $secret,
                    $this->getSalt($user)
                );
            }
            if ($isSecretValid && !$userApi->getUser()->getOrganizations()->contains($userApi->getOrganization())) {
                throw new BadCredentialsException('Wrong API key.');
            }
            if ($isSecretValid && !$userApi->getOrganization()->isEnabled()) {
                throw new BadCredentialsException('Organization is not active.');
            }

            // delete nonce from cache because user have another api keys
            if (!$isSecretValid && $secretsCount !== $currentIteration) {
                $this->getNonceCache()->delete($nonce);
            }

            if ($isSecretValid) {
                return $userApi;
            }
        }

        return false;
    }
}
