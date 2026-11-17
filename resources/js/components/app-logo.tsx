import AppLogoIcon from './app-logo-icon';

export default function AppLogo() {
    return (
        <>
            <AppLogoIcon className="size-8 shrink-0" alt="" />
            <div className="ml-1 grid flex-1 text-left leading-tight">
                <span className="truncate text-sm font-bold text-[#0b3466] dark:text-white">Mubarak</span>
                <span className="truncate text-[11px] font-semibold tracking-wide text-[#0e8a52] dark:text-emerald-300">Boardinghouse</span>
            </div>
        </>
    );
}
