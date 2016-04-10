<?php

namespace Riper\Security\ActiveDirectoryBundle\Security\User;

use adLDAP\adLDAP;
use adLDAP\collections\adLDAPUserCollection;
use Riper\Security\ActiveDirectoryBundle\Exception\ADConnexionException;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Translation\TranslatorInterface;
use Riper\Security\ActiveDirectoryBundle\Service\AdldapService;

class AdUserProvider implements UserProviderInterface
{
    private $usernamePatterns = array();
    private $recursiveGrouproles = false;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    private $config = array();

    public function __construct(array $config, AdldapService $AdldapService, TranslatorInterface $translator)
    {
        $this->config     = $config;
        $this->translator = $translator;

        $this->recursiveGrouproles = $this->getConfig('recursive_grouproles', false);
        $username_patterns         = $this->getConfig('username_patterns', array());
        foreach ($username_patterns as $pat) {
            array_push($this->usernamePatterns, $pat);
        }
    }

    /**
     * Retrieves a configuration value. make all required test.
     *
     * @param string $name Key name
     * @param mixed $default Default value
     *
     * @return mixed
     */
    protected function getConfig($name, $default)
    {
        if (!isset($this->config[$name])) {
            $return = $default;
        } else {
            $return = $this->config[$name];
        }

        return $return;
    }

    /**
     * Loads the user for the given username.
     *
     * This method must throw UsernameNotFoundException if the user is not
     * found.
     *
     * @param string $username The username
     *
     * @return AdUser
     *
     * @see UsernameNotFoundException
     *
     * @throws UsernameNotFoundException if the user is not found
     *
     */
    public function loadUserByUsername($username)
    {
        // The password is set to something impossible to find.
        try {
            $userString = $this->getUsernameFromString($username);
            $user = new AdUser(
                $this->getUsernameFromString($userString),
                uniqid(true) . rand(0, 424242),
                array()
            );
        } catch (\InvalidArgumentException $e) {
            $msg = $this->translator->trans(
                'riper.security.active_directory.invalid_user',
                array('%reason%' => $e->getMessage())
            );
            throw new UsernameNotFoundException($msg);
        }

        return $user;
    }


    /**
     * Retrieves the username from the login name, it is transformed using the username patterns.
     *
     * @param string $string
     *
     * @return string
     *
     * @throws \InvalidArgumentException
     */
    public function getUsernameFromString($string)
    {
        $username = $string;
        foreach ($this->usernamePatterns as $pattern) {
            if ($username == $string && preg_match($pattern, $string, $results)) {
                $username = $results[1];
                break;
            }
        }
        $username = strtolower($username);
        $pattern   = $this->getConfig('username_validation_pattern', '/^[a-z0-9-.]+$/i');
        if (preg_match($pattern, $username)) {
            return $username;
        }

        $msg = $this->translator->trans(
            'riper.security.active_directory.username_not_matching_rules',
            array(
                '%username%' => $username
            )
        );
        throw new \InvalidArgumentException($msg);
    }

    /**
     * Refreshes the user for the account interface.
     *
     * It is up to the implementation to decide if the user data should be
     * totally reloaded (e.g. from the database), or if the UserInterface
     * object can just be merged into some internal array of users / identity
     * map.
     *
     * @param UserInterface $user
     *
     * @return UserInterface
     *
     * @throws UnsupportedUserException if the account is not supported
     */
    public function refreshUser(UserInterface $user)
    {
        if (!$user instanceof AdUser) {
            $msg = $this->translator->trans(
                'riper.security.active_directory.bad_instance',
                array(
                    '%class_name%' => get_class($user)
                )
            );
            throw new UnsupportedUserException($msg);
        }

        return $user;
    }

    /**
     * Fetches the user data via adLDAP and stores it in the provided $adUser.
     *
     * @param AdUser $adUser
     * @param TokenInterface $token
     * @param adLDAP $adLdap
     */
    public function fetchData(AdUser $adUser, TokenInterface $token, adLDAP $adLdap)
    {
        $connected = $adLdap->connect();
        $isAD      = $adLdap->authenticate($adUser->getUsername(), $token->getCredentials());
        if (!$isAD || !$connected) {
            $msg = $this->translator->trans(
                'riper.security.active_directory.ad.bad_response',
                array(
                    '%connection_status%' => var_export($connected, 1),
                    '%is_AD%'             => var_export($isAD, 1),
                )
            );
            throw new ADConnexionException(
                $msg
            );
        }
        /** @var adLDAPUserCollection $user */
        $user = $adLdap->user()->infoCollection($adUser->getUsername());

        if ($user) {
            $groups = $adLdap->user()->groups($adUser->getUsername(), $this->recursiveGrouproles);
            /** End Fetching */
            $sfRoles = array();
            $sfRolesTemp = array();
            foreach ($groups as $r) {
                if (in_array($r, $sfRolesTemp) === false) {
                    $sfRoles[] = 'ROLE_' . strtoupper(str_replace(' ', '_', $r));
                    $sfRolesTemp[] = $r;
                }
            }
            $adUser->setRoles($sfRoles);
            unset($sfRolesTemp);

            $adUser->setDisplayName($user->displayName);
            $adUser->setEmail($user->mail);

            return true;
        }
    }

    /**
     * Whether this provider supports the given user class
     *
     * @param string $class
     *
     * @return Boolean
     */
    public function supportsClass($class)
    {
        return $class === 'Riper\Security\ActiveDirectoryBundle\Security\User\AdUser';
    }
}
