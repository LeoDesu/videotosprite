#!/bin/bash

input="$1"
output="$2"
tmpdir="tmp"
temp="${tmpdir}/tmpname"

#scale the video down for faster reading
scaleDown(){
    echo "[] scaling down..."
    ffmpeg -loglevel "quiet" -i "$1" -vf "fps=10,scale=320:-1" "${temp}.mp4"
}

extractImages(){
    duration=$(ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 "$1")
    gap=$(echo "scale=5; $duration / 100" | bc)
    echo "[] duration: $duration"
    echo "[] duration gap: $gap"
    for i in {0..99}; do
        printf "[] extracting images $(($i+1))%%\r"
        ss=$(echo "$i * $gap" | bc)
        ffmpeg -loglevel "quiet" -i "$1" -ss $ss -frames 1 "${temp}${i}.jpg"
    done
}

hstack(){
    ffmpeg -loglevel "quiet" -i "$1" -i "$2" -filter_complex "hstack" "$3"
}

transpose(){
    ffmpeg -loglevel "quiet" -i "$1" -vf "transpose=2" "$2"
}

transposeback(){
    ffmpeg -loglevel "quiet" -i "$1" -vf "transpose=1" "$2"
}

createSprite(){
    echo -e "\n[] creating sprite..."
    unset numls10
    for i in {0..9}; do
        printf "\r[] combine to row number $i"
        unset numls2
        unset numls4
        #make a list of 2 numbers
        for j in {0..9..2}; do
            jj=$(($j+1))
            num1=$(($i * 10 + $j))
            num2=$(($i * 10 + $jj))
            num="${num1}${num2}"
            numls2=( ${numls2[@]} $num )
            hstack "${temp}${num1}.jpg" "${temp}${num2}.jpg" "${temp}hs${num}.jpg"
            rm "${temp}${num1}.jpg" "${temp}${num2}.jpg"
        done

        #make a list of 4 numbers
        for j in {0..3..2}; do
            num="${numls2[$j]}${numls2[$(($j+1))]}"
            numls4=( ${numls4[@]} $num )
            hstack "${temp}hs${numls2[$j]}.jpg" "${temp}hs${numls2[$(($j+1))]}.jpg" "${temp}hs${num}.jpg"
            rm "${temp}hs${numls2[$j]}.jpg" "${temp}hs${numls2[$(($j+1))]}.jpg"
        done

        #hstack 4 numbers together
        num="${numls4[0]}${numls4[1]}"
        hstack "${temp}hs${numls4[0]}.jpg" "${temp}hs${numls4[1]}.jpg" "${temp}hs${num}.jpg"
        rm "${temp}hs${numls4[0]}.jpg" "${temp}hs${numls4[1]}.jpg"
        #hstack 8 numbers with last 2 numbers
        hstack "${temp}hs${num}.jpg" "${temp}hs${numls2[4]}.jpg" "${temp}hs${num}${numls2[4]}.jpg"
        rm "${temp}hs${num}.jpg" "${temp}hs${numls2[4]}.jpg"
        numls10=( ${numls10[@]} "${num}${numls2[4]}" )
        transpose "${temp}hs${num}${numls2[4]}.jpg" "${temp}hs${num}${numls2[4]}t.jpg"
        rm "${temp}hs${num}${numls2[4]}.jpg"
    done

    #convert list of 10 numbers into list of characters
    unset chls
    chls=({a..j})
    for i in {0..9}; do
        mv "${temp}hs${numls10[$i]}t.jpg" "${temp}hs${chls[$i]}t.jpg"
    done

    unset chls2
    unset chls4
    printf "\r[] combine to sprite...\n"
    for i in {0..9..2}; do
        ii=$(($i+1))
        num1=${chls[$i]}
        num2=${chls[$ii]}
        num="${num1}${num2}"
        chls2=( ${chls2[@]} $num )
        hstack "${temp}hs${num1}t.jpg" "${temp}hs${num2}t.jpg" "${temp}hs${num}t.jpg"
        rm "${temp}hs${num1}t.jpg" "${temp}hs${num2}t.jpg"
    done
    for i in {0..3..2}; do
        num="${chls2[$i]}${chls2[$(($i+1))]}"
        chls4=( ${chls4[@]} $num )
        hstack "${temp}hs${chls2[$i]}t.jpg" "${temp}hs${chls2[$(($i+1))]}t.jpg" "${temp}hs${num}t.jpg"
        rm "${temp}hs${chls2[$i]}t.jpg" "${temp}hs${chls2[$(($i+1))]}t.jpg"
    done
    #hstack 4 numbers together
    num="${chls4[0]}${chls4[1]}"
    hstack "${temp}hs${chls4[0]}t.jpg" "${temp}hs${chls4[1]}t.jpg" "${temp}hs${num}t.jpg"
    rm "${temp}hs${chls4[0]}t.jpg" "${temp}hs${chls4[1]}t.jpg"
    #hstack 8 numbers with last 2 numbers
    hstack "${temp}hs${num}t.jpg" "${temp}hs${chls2[4]}t.jpg" "${temp}hs${num}${chls2[4]}t.jpg"
    rm "${temp}hs${num}t.jpg" "${temp}hs${chls2[4]}t.jpg"

    transposeback "${temp}hs${num}${chls2[4]}t.jpg" "${output}.jpg"
    rm "${temp}hs${num}${chls2[4]}t.jpg"
}

if [ -f "${output}.jpg" ]; then
    echo -e "[videotosprite] file \"${output}.jpg\" is already exist."
else
    if [ -z "$input" -o -z "$output" ]; then
        echo -e "usage: videotosprite <input.mp4> <outputname>\n\n"\
        "#<outputname> the program will automaticaly generate as .jpg file\n"\
        "videotosprite generates sprite sheet from a .mp4 video with the dimension of 10x10\n"
    else
        mkdir $tmpdir
        scaleDown "$input"
        extractImages "${temp}.mp4"
        createSprite "${temp}.mp4"
        rm "${temp}.mp4"
        rm -r $tmpdir
    fi
fi
