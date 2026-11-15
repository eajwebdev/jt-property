import { SVGAttributes } from 'react';

export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="JT Property">
            <title>JT Property</title>
            <rect width="64" height="64" rx="14" fill="#064578" />
            <path d="M14 46h36" stroke="#24C5A4" strokeWidth="4" strokeLinecap="round" />
            <text x="32" y="38" textAnchor="middle" fontFamily="Instrument Sans, Arial, sans-serif" fontSize="26" fontWeight="700" fill="#fff">
                JT
            </text>
        </svg>
    );
}