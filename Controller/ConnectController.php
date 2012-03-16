<?php

/*
 * This file is part of the HWIOAuthBundle package.
 *
 * (c) Hardware.Info <opensource@hardware.info>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace HWI\Bundle\OAuthBundle\Controller;

use HWI\Bundle\OAuthBundle\Security\Core\Exception\AccountNotLinkedException;

use Symfony\Component\DependencyInjection\ContainerAware,
    Symfony\Component\HttpFoundation\Request,
    Symfony\Component\HttpFoundation\RedirectResponse,
    Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken,
    Symfony\Component\Security\Core\Exception\AuthenticationException,
    Symfony\Component\Security\Core\SecurityContext,
    Symfony\Component\Security\Core\User\UserInterface;

/**
 * ConnectController
 *
 * @author Alexander <iam.asm89@gmail.com>
 */
class ConnectController extends ContainerAware
{
    /**
     * Action that handles the login 'form'. If connecting is enabled the
     * user will be redirected to the appropriate login urls or registration forms.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function connectAction(Request $request)
    {
        $connect = $this->container->getParameter('hwi_oauth.connect');
        $hasUser = $this->container->get('security.context')->isGranted('IS_AUTHENTICATED_REMEMBERED');

        $error = $this->getErrorForRequest($request);

        // if connecting is enabled and there is no user, redirect to the registration form
        if ($connect
            && !$hasUser
            && $error instanceof AccountNotLinkedException
        ) {
            $key = time();
            $session = $request->getSession();
            $session->set('_hwi_oauth.registration_error.'.$key, $error);

            return new RedirectResponse($this->generate('hwi_oauth_connect_registration', array('key' => $key)));
        }

        if ($error) {
            // TODO: this is a potential security risk (see http://trac.symfony-project.org/ticket/9523)
            $error = $error->getMessage();
        }

        return $this->container->get('templating')->renderResponse('HWIOAuthBundle:Connect:login.html.twig', array(
            'error'           => $error,
            'resource_owners' => $this->getResourceOwners($request, $connect && $hasUser),
        ));
    }

    /**
     * Shows a registration form if there is no user logged in and connecting
     * is enabled.
     *
     * @param Request $request A request.
     * @param string  $key     Key used for retrieving the right information for the registration form.
     *
     * @return Response
     */
    public function registrationAction(Request $request, $key)
    {
        $hasUser = $this->container->get('security.context')->isGranted('IS_AUTHENTICATED_REMEMBERED');
        $connect = $this->container->getParameter('hwi_oauth.connect');

        $session = $request->getSession();
        $error = $session->get('_hwi_oauth.registration_error.'.$key);
        $session->remove('_hwi_oauth.registration_error.'.$key);

        if (!$connect || $hasUser || !($error instanceof AccountNotLinkedException) || (time() - $key > 300)) {
            // todo: fix this
            throw new \Exception('Cannot register an account.');
        }

        $userInformation = $this->getResourceOwnerByName($error->getResourceOwnerName())
            ->getUserInformation($error->getAccessToken());

        $form = $this->container->get('hwi_oauth.registration.form');
        $formHandler = $this->container->get('hwi_oauth.registration.form.handler');
        if ($formHandler->process($request, $form, $userInformation)) {
            $this->container->get('hwi_oauth.account.connector')->connect($form->getData(), $userInformation);

            // Authenticate the user
            $this->authenticateUser($form->getData());

            return new RedirectResponse($this->generate('hwi_oauth_connect_registration_success'));
        }

        // reset the error in the session
        $key = time();
        $session->set('_hwi_oauth.registration_error.'.$key, $error);

        return $this->container->get('templating')->renderResponse('HWIOAuthBundle:Connect:registration.html.twig', array(
            'key' => $key,
            'form' => $form->createView(),
            'userInformation' => $userInformation,
        ));
    }

    /**
     * Shows a message when a user was successfuly registered.
     *
     * @return Response
     */
    public function registrationSuccessAction()
    {
        $user = $this->container->get('security.context')->getToken()->getUser();

        if (null === $user || !$user instanceof UserInterface) {
            // todo: fix this
            throw new \Exception();
        }

        return $this->container->get('templating')->renderResponse('HWIOAuthBundle:Connect:registration_success.html.twig');
    }

    /**
     * Connects a user to a given account if the user is logged in and connect is enabled.
     *
     * @param Request $request The active request.
     * @param string  $service Name of the resource owner to connect to.
     *
     * @return Response
     */
    public function connectServiceAction(Request $request, $service)
    {
        $hasUser = $this->container->get('security.context')->isGranted('IS_AUTHENTICATED_REMEMBERED');
        $connect = $this->container->getParameter('hwi_oauth.connect');

        if (!$connect || !$hasUser) {
            // todo: fix this
            throw new \Exception('Cannot connect an account.');
        }

        // Get the data from the resource owner
        $resourceOwner = $this->getResourceOwnerByName($service);

        $session = $request->getSession();
        $key = $request->query->get('key', time());

        if (null !== $code = $request->query->get('code')) {
            $accessToken = $resourceOwner->getAccessToken(
                $request->query->get('code'),
                $this->generate('hwi_oauth_connect_service', array('service' => $service), true)
            );

            // save in session
            $session->set('_hwi_oauth.connect_confirmation.'.$key, $accessToken);
        } else {
            $accessToken = $session->get('_hwi_oauth.connect_confirmation.'.$key);
        }

        $userInformation = $resourceOwner->getUserInformation($accessToken);

        // Handle the form
        $form = $this->container->get('form.factory')
            ->createBuilder('form')
            ->getForm();

        if ('POST' === $request->getMethod()) {
            $form->bindRequest($request);

            if ($form->isValid()) {
                $user = $this->container->get('security.context')->getToken()->getUser();

                $this->container->get('hwi_oauth.account.connector')->connect($user, $userInformation);

                return $this->container->get('templating')->renderResponse('HWIOAuthBundle:Connect:connect_success.html.twig', array(
                    'userInformation' => $userInformation,
                ));
            }
        }

        return $this->container->get('templating')->renderResponse('HWIOAuthBundle:Connect:connect_confirm.html.twig', array(
            'key' => $key,
            'service' => $service,
            'form' => $form->createView(),
            'userInformation' => $userInformation,
        ));
    }

    /**
     * Gets all the resource owners for a given request.
     *
     * @param Request $request A request.
     * @param boolean $connect Whether it should be connect, or just 'regular' redirect urls.
     *
     * @return array Resource owner information.
     */
    protected function getResourceOwners(Request $request, $connect)
    {
        $ownerMap = $this->container->get('hwi_oauth.resource_ownermap.'.$this->container->getParameter('hwi_oauth.firewall_name'));

        $resourceOwners = array();
        foreach ($ownerMap->getResourceOwners() as $name => $checkPath) {
            $resourceOwner = $ownerMap->getResourceOwnerByName($name);
            $resourceOwners[] = array(
                'url' => $resourceOwner->getAuthorizationUrl(
                    $connect
                    ? $this->generate('hwi_oauth_connect_service', array('service' => $name), true)
                    : $this->getUriForCheckPath($request, $checkPath)
                ),
                'name' => $name,
            );
        }

        return $resourceOwners;
    }

    /**
     * Get the uri for a given path.
     *
     * @param Request $request A request instance
     * @param string  $path    Path or route
     *
     * @return string
     */
    protected function getUriForCheckPath(Request $request, $path)
    {
        if ($path && '/' !== $path[0] && 0 !== strpos($path, 'http')) {
            $path = $this->generate($path, array(), true);
        }

        if (0 !== strpos($path, 'http')) {
            $path = $request->getUriForPath($path);
        }

        return $path;
    }

    /**
     * Get the security error for a given request.
     *
     * @param Request $request
     *
     * @return string|Exception
     */
    protected function getErrorForRequest(Request $request)
    {
        $session = $request->getSession();
        if ($request->attributes->has(SecurityContext::AUTHENTICATION_ERROR)) {
            $error = $request->attributes->get(SecurityContext::AUTHENTICATION_ERROR);
        } elseif (null !== $session && $session->has(SecurityContext::AUTHENTICATION_ERROR)) {
            $error = $session->get(SecurityContext::AUTHENTICATION_ERROR);
            $session->remove(SecurityContext::AUTHENTICATION_ERROR);
        } else {
            $error = '';
        }

        return $error;
    }

    /**
     * Get a resource owner by name.
     *
     * @param string $name
     *
     * @return ResourceOwnerInterface
     *
     * @throws \RuntimeException if there is no resource owner with the given name.
     */
    protected function getResourceOwnerByName($name)
    {
        $ownerMap = $this->container->get('hwi_oauth.resource_ownermap.'.$this->container->getParameter('hwi_oauth.firewall_name'));

        if (null === $resourceOwner = $ownerMap->getResourceOwnerByName($name)) {
            throw new \RuntimeException(sprintf("No resource owner with name '%s'.", $name));
        }

        return $resourceOwner;
    }

    /**
     * Generates a route.
     *
     * @param string  $route    Route name
     * @param array   $params   Route parameters
     * @param boolean $absolute Absolute url or note.
     *
     * @return string
     */
    protected function generate($route, $params = array(), $absolute = false)
    {
        return $this->container->get('router')->generate($route, $params, $absolute);
    }

    /**
    * Authenticate a user with Symfony Security
    *
    * @param UserInterface $user
    */
    protected function authenticateUser(UserInterface $user)
    {
        try {
            $this->container->get('hwi_oauth.user_checker')->checkPostAuth($user);
        } catch (AccountStatusException $e) {
            // Don't authenticate locked, disabled or expired users
            return;
        }

        $providerKey = $this->container->getParameter('hwi_oauth.firewall_name');
        $token = new UsernamePasswordToken($user, null, $providerKey, $user->getRoles());

        $this->container->get('security.context')->setToken($token);
    }
}