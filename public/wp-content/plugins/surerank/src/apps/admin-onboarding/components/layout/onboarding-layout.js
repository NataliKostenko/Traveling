import { __ } from '@wordpress/i18n';
import { Topbar, ProgressSteps, Toaster, Button, Text } from '@bsf/force-ui';
import { ArrowRight, Zap } from 'lucide-react';
import { SureRankFullLogo } from '@GlobalComponents/icons';
import { cn } from '@Functions/utils';
import { Outlet, useLocation } from '@tanstack/react-router';
import { ONBOARDING_STEPS_CONFIG } from '@Onboarding/index';
import { OnboardingProvider } from '@Onboarding/store';
import useOnboardingAuth from '@Onboarding/hooks/use-onboarding-auth';
import ExitButton from '@Onboarding/components/exit-button';
import TanStackRouterDevtools from '@/apps/admin-components/tanstack-router-dev-tools';
import Alert from '@/global/components/alert';
import { isProActive, redirectToPricingPage } from '@/functions/nudges';

const OnboardingLayout = () => {
	const currentStepURL = useLocation( {
		select: ( location ) => location.pathname,
	} );
	const { isAuthenticated } = useOnboardingAuth();

	const visibleSteps = isAuthenticated
		? ONBOARDING_STEPS_CONFIG.filter(
				( step ) => step.path !== '/user-details'
		  )
		: ONBOARDING_STEPS_CONFIG;

	const currentStep = visibleSteps.findIndex(
		( step ) => step.path === currentStepURL
	);
	const {
		config: { containerSize = 'sm' },
	} = visibleSteps[ currentStep ] || {
		config: { containerSize: 'sm' },
	};

	let containerClassNames;
	let maxWidthClassName;
	switch ( containerSize ) {
		case 'sm':
			containerClassNames = 'max-w-onboarding-container-1';
			maxWidthClassName = 'max-w-onboarding-container-1';
			break;
		case 'md':
			containerClassNames = 'max-w-onboarding-container-2 p-7';
			maxWidthClassName = 'max-w-onboarding-container-2';
			break;
		case 'lg':
			containerClassNames = 'max-w-onboarding-container-3 p-8';
			maxWidthClassName = 'max-w-onboarding-container-3';
			break;
		default:
			containerClassNames = 'max-w-onboarding-container-1';
			maxWidthClassName = 'max-w-onboarding-container-1';
			break;
	}

	// Pro upgrade nudge shown below the card on the final (finish) step only.
	const isFinishStep = currentStepURL === '/finish';
	const showUpgradeNudge = isFinishStep && ! isProActive();

	return (
		<>
			<Toaster />
			<OnboardingProvider>
				<div className="grid grid-cols-1 grid-rows-[3.5rem_1fr] w-full h-full">
					{ /* Topbar */ }
					<Topbar
						className={ cn( 'z-[1] p-4 min-h-14 bg-transparent' ) }
					>
						<Topbar.Left>
							<Topbar.Item>
								<SureRankFullLogo className="w-[127px] h-[20px]" />
							</Topbar.Item>
						</Topbar.Left>
						<Topbar.Middle
							align="center"
							className="w-full max-w-95 hidden md:flex"
						>
							<ProgressSteps
								currentStep={ currentStep + 1 }
								size="md"
								type="inline"
								variant="number"
								completedVariant="number"
							>
								{ Array.from( {
									length: visibleSteps.length - 1,
								} ).map( ( _, index ) => (
									<ProgressSteps.Step key={ index } />
								) ) }
							</ProgressSteps>
						</Topbar.Middle>
						<Topbar.Right>
							<ExitButton />
						</Topbar.Right>
					</Topbar>
					{ /* Content */ }
					<div className="flex flex-col items-center justify-start p-10">
						<div
							className={ cn(
								'w-full h-full max-w-onboarding-container mx-auto flex flex-col gap-4',
								maxWidthClassName
							) }
						>
							<div
								className={ cn(
									'w-full max-w-onboarding-container mx-auto border border-border-subtle rounded-xl p-6 bg-background-primary shadow-sm',
									! isFinishStep && 'h-full',
									containerClassNames
								) }
							>
								<Outlet />
							</div>
							{ showUpgradeNudge && (
								<Alert
									color="info"
									className="items-center"
									renderIcon={
										<div className="flex items-center justify-center size-10 rounded-lg bg-gradient-to-br from-brand-400 to-brand-500 shadow-md ring-1 ring-inset ring-white/20 shrink-0">
											<Zap
												className="size-5 text-white fill-white"
												strokeWidth={ 0 }
											/>
										</div>
									}
									message={
										<Text
											size={ 14 }
											weight={ 400 }
											color="primary"
										>
											{ __( 'Unlock', 'surerank' ) }{ ' ' }
											<span className="font-semibold">
												{ __(
													'SureRank Pro',
													'surerank'
												) }
											</span>{ ' ' }
											{ __(
												'for AI-powered SEO fixes, advanced schema, instant indexing, and a lot more.',
												'surerank'
											) }
										</Text>
									}
									action={
										<Button
											size="sm"
											variant="link"
											className="no-underline hover:underline whitespace-nowrap"
											icon={ <ArrowRight /> }
											iconPosition="right"
											onClick={ () =>
												redirectToPricingPage(
													'onboarding_success'
												)
											}
										>
											{ __(
												'Upgrade to Pro',
												'surerank'
											) }
										</Button>
									}
								/>
							) }
						</div>
					</div>
				</div>
			</OnboardingProvider>
			<TanStackRouterDevtools />
		</>
	);
};

export default OnboardingLayout;
