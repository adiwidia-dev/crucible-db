import type { SVGAttributes } from 'react';

export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg
            {...props}
            viewBox="0 0 48 48"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
        >
            <path
                d="M10.5 11.75C10.5 8.16 16.32 5.25 23.5 5.25C30.68 5.25 36.5 8.16 36.5 11.75C36.5 15.34 30.68 18.25 23.5 18.25C16.32 18.25 10.5 15.34 10.5 11.75Z"
                stroke="currentColor"
                strokeWidth="3.4"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
            <path
                d="M10.5 11.75V29.75C10.5 33.04 15.39 35.76 21.74 36.2"
                stroke="currentColor"
                strokeWidth="3.4"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
            <path
                d="M36.5 11.75V22.25"
                stroke="currentColor"
                strokeWidth="3.4"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
            <path
                d="M10.5 20.75C10.5 24.34 16.32 27.25 23.5 27.25C26.92 27.25 30.03 26.59 32.35 25.51"
                stroke="currentColor"
                strokeWidth="3.4"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
            <path
                d="M10.5 29.75C10.5 33.34 16.32 36.25 23.5 36.25"
                stroke="currentColor"
                strokeWidth="3.4"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
            <path
                d="M31.25 24.75H41L35.62 32.75H42.25L29.75 45L32.5 35.5H26.25L31.25 24.75Z"
                fill="currentColor"
            />
        </svg>
    );
}
